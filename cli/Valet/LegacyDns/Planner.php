<?php

namespace Valet\LegacyDns;

use Valet\LegacyDns\Inspector as I;

/**
 * Turns inspected facts into a migration plan. Pure: it never touches the
 * system, so every decision can be previewed and tested.
 */
class Planner
{
    private const RESOLVED_FILES = [
        '/run/systemd/resolve/stub-resolv.conf',
        '/run/systemd/resolve/resolv.conf',
        '/usr/lib/systemd/resolv.conf',
        '/lib/systemd/resolv.conf',
    ];

    /**
     * @var array<string, mixed>
     */
    private array $facts;
    private Plan $plan;

    /**
     * @var list<string>
     */
    private array $removed = [];

    /**
     * Final content of /etc/dnsmasq.conf after planned changes.
     */
    private ?string $dnsmasqConf = null;

    /**
     * @param array<string, mixed>  $facts   Output of Inspector::inspect()
     * @param array<string, string> $choices Decision overrides, e.g. ['resolved' => 'restore']
     */
    public function plan(array $facts, array $choices = []): Plan
    {
        $this->facts = $facts;
        $this->plan = new Plan();
        $this->removed = [];
        $this->dnsmasqConf = $this->content(I::DNSMASQ_CONF);

        $this->detect();
        if (!$this->plan->legacy()) {
            $this->residueNotes();
            $this->applyChoices($choices);

            return $this->plan;
        }

        $files = [];
        $services = [];
        $cleanup = [];

        $this->planWatcher($files, $services, $cleanup);
        $this->planResolvConf($files);
        $this->planDnsmasqFiles($files);
        $this->planNetworkManager($files, $services);
        $this->decideResolved();
        $this->decideDnsmasq();
        $this->applyChoices($choices);
        $resolverSteps = $this->resolverSteps();
        $this->planWatcherDirectory($cleanup);
        $this->notes();

        // Immutable files cannot be renamed, replaced or removed. Keep the
        // flag on whatever ends up holding the locked content.
        $steps = [];
        $relock = [];
        foreach (array_merge($files, $cleanup) as $step) {
            foreach (array_filter([$step['path'] ?? null, $step['from'] ?? null, $step['to'] ?? null]) as $path) {
                if (($this->node((string) $path)['immutable'] ?? false) && !in_array($path, array_column($steps, 'path'), true)) {
                    $steps[] = ['op' => 'immutable', 'path' => $path, 'value' => false, 'reason' => 'the file is locked with chattr +i'];
                    if ($step['op'] === 'file-restore' && $path === $step['from']) {
                        $relock[] = ['op' => 'immutable', 'path' => $step['to'], 'value' => true, 'reason' => 'keep the original lock'];
                    }
                }
            }
        }
        // Services that rewrite DNS files must stop before files change.
        foreach ($services as $step) {
            $steps[] = $step;
        }
        foreach ($files as $step) {
            $steps[] = $step;
        }
        foreach ($relock as $step) {
            $steps[] = $step;
        }
        foreach ($resolverSteps as $step) {
            $steps[] = $step;
        }
        foreach ($cleanup as $step) {
            $steps[] = $step;
        }

        $domain = $facts['domain'] ?? null;
        if (is_string($domain) && $domain !== '' && $this->plan->conflicts === []) {
            $steps[] = ['op' => 'valet-backend', 'domain' => $domain,
                'reason' => 'replaces the legacy wildcard after normal DNS is verified'];
        }
        $this->plan->steps = $steps;

        return $this->plan;
    }

    private function detect(): void
    {
        $watcher = false;
        $wildcard = false;

        $options = $this->content(I::DNSMASQ_OPTIONS);
        if (Fingerprints::matches($options, Fingerprints::OPTIONS_WATCHER)) {
            $watcher = true;
            $this->marker(I::DNSMASQ_OPTIONS . ' matches the valet-dns era options (1.0 - 1.6.0)');
        } elseif (Fingerprints::matches($options, Fingerprints::OPTIONS_WILDCARD)) {
            $wildcard = true;
            $this->marker(I::DNSMASQ_OPTIONS . ' matches the 1.6.1 - 2.0.3 options (dnsmasq on every address)');
        }

        $valet = Fingerprints::valetConfig($this->content(I::DNSMASQ_VALET));
        if ($valet !== null) {
            $this->marker(sprintf(
                '%s resolves %s to 127.0.0.1%s',
                I::DNSMASQ_VALET,
                $valet['domain'] === '' ? 'every name (written without a configured domain)' : '.' . $valet['domain'],
                $valet['publicUpstreams'] ? ' and forces 1.1.1.1 and 8.8.8.8 as upstream DNS' : ''
            ));
            $wildcard = $wildcard || $valet['publicUpstreams'];
        }
        if (Fingerprints::matches($this->content(I::DNSMASQ_CONF), Fingerprints::DNSMASQ_CONF)) {
            $this->marker(I::DNSMASQ_CONF . ' is the Valet replacement file');
        }
        if (Fingerprints::matches($this->content(I::NM_VALET), Fingerprints::NETWORK_MANAGER)) {
            $this->marker(I::NM_VALET . ' disables NetworkManager DNS handling (dns=none)');
        }
        if (Fingerprints::matches($this->content(I::WATCHER_UNIT), Fingerprints::WATCHER_SYSTEMD_UNIT)
            || Fingerprints::matches($this->content(I::WATCHER_SYSV), Fingerprints::WATCHER_SYSV_SCRIPT)
            || Fingerprints::matches($this->content(I::WATCHER_DIR . '/valet-dns'), Fingerprints::WATCHER_SCRIPT)) {
            $watcher = true;
            $this->marker('The valet-dns resolv.conf watcher is installed');
        }
        if ($this->resolvIntoWatcherDir()) {
            $watcher = true;
            $dangling = $this->node(I::WATCHER_DIR . '/resolv.conf') === null;
            $this->marker(I::RESOLV . ' points into ' . I::WATCHER_DIR . ($dangling ? ' (dangling)' : ''));
            if ($dangling) {
                $wildcard = true;
            }
        }
        if ($this->node(I::WATCHER_DIR) !== null && $this->plan->markers !== []) {
            $this->marker(I::WATCHER_DIR . ' exists');
        }

        if ($this->plan->markers === []) {
            return;
        }
        $this->plan->generation = match (true) {
            $watcher && $wildcard => 'mixed: valet-dns layout upgraded to 1.6.1+',
            $watcher => 'valet-dns watcher, 1.0 - 1.6.0',
            $wildcard => 'system dnsmasq, 1.6.1 - 2.0.3',
            default => 'partial',
        };
    }

    /**
     * @param list<array<string, mixed>> $files
     * @param list<array<string, mixed>> $services
     * @param list<array<string, mixed>> $cleanup
     */
    private function planWatcher(array &$files, array &$services, array &$cleanup): void
    {
        $unit = $this->node(I::WATCHER_UNIT);
        $unitState = $this->unit('valet-dns.service');
        if ($unit !== null) {
            if (!Fingerprints::matches($unit['content'], Fingerprints::WATCHER_SYSTEMD_UNIT)) {
                $this->plan->conflicts[] = I::WATCHER_UNIT . ' was modified; it may still rewrite resolv.conf. Review it manually.';

                return;
            }
            $services[] = ['op' => 'unit', 'unit' => 'valet-dns.service', 'enable' => false, 'action' => 'stop',
                'reason' => 'the watcher rewrites /etc/resolv.conf'];
            $files[] = ['op' => 'file-remove', 'path' => I::WATCHER_UNIT, 'reason' => 'unmodified Valet unit'];
            $cleanup[] = ['op' => 'daemon-reload'];
        } elseif ($unitState['active'] === 'active') {
            $this->plan->conflicts[] = 'valet-dns.service is running from an unexpected location.';
        }

        $sysv = $this->node(I::WATCHER_SYSV);
        if ($sysv !== null) {
            if (!Fingerprints::matches($sysv['content'], Fingerprints::WATCHER_SYSV_SCRIPT)) {
                $this->plan->conflicts[] = I::WATCHER_SYSV . ' was modified. Review it manually.';

                return;
            }
            $services[] = ['op' => 'command', 'run' => I::WATCHER_SYSV . ' stop',
                'undo' => I::WATCHER_SYSV . ' start', 'reason' => 'the watcher rewrites /etc/resolv.conf'];
            $services[] = ['op' => 'command',
                'run' => 'if command -v update-rc.d >/dev/null; then update-rc.d -f valet-dns remove; '
                    . 'elif command -v chkconfig >/dev/null; then chkconfig --del valet-dns; fi',
                'undo' => 'if command -v update-rc.d >/dev/null; then update-rc.d valet-dns defaults; '
                    . 'elif command -v chkconfig >/dev/null; then chkconfig --add valet-dns; fi',
                'reason' => 'remove boot links'];
            $files[] = ['op' => 'file-remove', 'path' => I::WATCHER_SYSV, 'reason' => 'unmodified Valet init script'];
        }
    }

    /**
     * @param list<array<string, mixed>> $files
     */
    private function planResolvConf(array &$files): void
    {
        if (!$this->resolvIntoWatcherDir()) {
            return;
        }

        $backup = $this->node(I::RESOLV_BACKUP);
        if ($backup !== null && !($backup['type'] === 'link'
            && str_starts_with(System::absoluteTarget(I::RESOLV_BACKUP, (string) $backup['target']), I::WATCHER_DIR . '/'))) {
            $files[] = ['op' => 'file-restore', 'from' => I::RESOLV_BACKUP, 'to' => I::RESOLV,
                'reason' => 'the watcher moved the original aside to ' . I::RESOLV_BACKUP];
            $this->plan->evidence[] = sprintf('The pre-Valet resolv.conf is preserved in %s (%s).', I::RESOLV_BACKUP, $this->describeNode($backup, I::RESOLV_BACKUP));

            return;
        }

        $this->plan->conflicts[] = I::RESOLV . ' points into ' . I::WATCHER_DIR . ' and no usable '
            . I::RESOLV_BACKUP . ' records the original. Recreate /etc/resolv.conf for your resolver manually '
            . '(for systemd-resolved: ln -sf ../run/systemd/resolve/stub-resolv.conf /etc/resolv.conf).';
    }

    /**
     * @param list<array<string, mixed>> $files
     */
    private function planDnsmasqFiles(array &$files): void
    {
        $valet = $this->node(I::DNSMASQ_VALET);
        if ($valet !== null) {
            if (Fingerprints::valetConfig($valet['content']) !== null) {
                $files[] = ['op' => 'file-remove', 'path' => I::DNSMASQ_VALET, 'reason' => 'unmodified Valet wildcard and upstream configuration'];
                $this->removed[] = I::DNSMASQ_VALET;
            } else {
                $this->plan->warnings[] = I::DNSMASQ_VALET . ' contains local changes and is kept.';
            }
        }

        $options = $this->content(I::DNSMASQ_OPTIONS);
        if (Fingerprints::matches($options, array_merge(Fingerprints::OPTIONS_WATCHER, Fingerprints::OPTIONS_WILDCARD))) {
            $files[] = ['op' => 'file-remove', 'path' => I::DNSMASQ_OPTIONS, 'reason' => 'unmodified Valet dnsmasq options'];
            $this->removed[] = I::DNSMASQ_OPTIONS;
        } elseif ($options !== null) {
            $this->plan->warnings[] = I::DNSMASQ_OPTIONS . ' is not a Valet file and is kept.';
        }

        $conf = $this->node(I::DNSMASQ_CONF);
        $backup = $this->node(I::DNSMASQ_CONF_BACKUP);
        if ($conf !== null && Fingerprints::matches($conf['content'], Fingerprints::DNSMASQ_CONF)) {
            if ($backup !== null && $backup['type'] === 'file'
                && !Fingerprints::matches($backup['content'], Fingerprints::DNSMASQ_CONF)) {
                $files[] = ['op' => 'file-restore', 'from' => I::DNSMASQ_CONF_BACKUP, 'to' => I::DNSMASQ_CONF,
                    'reason' => 'Valet moved the original aside before replacing it'];
                $this->dnsmasqConf = $backup['content'];
                $pristine = $this->pristine(I::DNSMASQ_CONF, $backup['content']);
                if ($pristine === true) {
                    $this->plan->evidence[] = I::DNSMASQ_CONF_BACKUP . ' matches the packaged default.';
                } elseif ($pristine === false) {
                    $this->plan->warnings[] = I::DNSMASQ_CONF_BACKUP . ' differs from the packaged default. It may '
                        . 'contain your own settings or predate Valet; it is restored as found. Review it afterwards.';
                }
            } else {
                $this->plan->evidence[] = 'No original ' . I::DNSMASQ_CONF . ' backup exists; the Valet copy only contains comments and conf-dir and is kept.';
            }
        } elseif ($backup !== null) {
            $this->plan->warnings[] = I::DNSMASQ_CONF_BACKUP . ' exists but ' . I::DNSMASQ_CONF . ' is not the Valet file; both are kept.';
        }

        $default = $this->node(I::DNSMASQ_DEFAULT);
        if ($default !== null && $default['type'] === 'file'
            && preg_match('/^IGNORE_RESOLVCONF=yes$/m', (string) $default['content'])) {
            $reverted = (string) preg_replace('/^IGNORE_RESOLVCONF=yes$/m', '#IGNORE_RESOLVCONF=yes', (string) $default['content']);
            $pristine = $this->pristine(I::DNSMASQ_DEFAULT, $reverted);
            if ($pristine === false && $this->pristine(I::DNSMASQ_DEFAULT, (string) $default['content']) === true) {
                $this->plan->evidence[] = I::DNSMASQ_DEFAULT . ' is already the packaged default.';
            } else {
                $files[] = ['op' => 'file-write', 'path' => I::DNSMASQ_DEFAULT, 'content' => $reverted,
                    'mode' => $default['mode'], 'uid' => $default['uid'], 'gid' => $default['gid'],
                    'reason' => 'comment IGNORE_RESOLVCONF again, as Valet uncommented it'];
                if ($pristine === false) {
                    $this->plan->warnings[] = I::DNSMASQ_DEFAULT . ' has other local changes; only the IGNORE_RESOLVCONF line is reverted.';
                }
            }
        }

        $package = $this->facts['packages'][I::DNSMASQ_NM] ?? null;
        if ($this->node(I::DNSMASQ_NM) === null && is_array($package) && $package['digest'] !== null) {
            $files[] = ['op' => 'package-file', 'path' => I::DNSMASQ_NM, 'package' => $package['package'],
                'algorithm' => $package['algorithm'], 'digest' => $package['digest'], 'optional' => true,
                'reason' => 'Valet deleted this packaged file without a backup'];
        }
    }

    /**
     * @param list<array<string, mixed>> $files
     * @param list<array<string, mixed>> $services
     */
    private function planNetworkManager(array &$files, array &$services): void
    {
        $node = $this->node(I::NM_VALET);
        if ($node === null) {
            return;
        }
        if (!Fingerprints::matches($node['content'], Fingerprints::NETWORK_MANAGER)) {
            $this->plan->warnings[] = I::NM_VALET . ' contains local changes and is kept.';

            return;
        }
        $files[] = ['op' => 'file-remove', 'path' => I::NM_VALET, 'reason' => 'NetworkManager manages DNS again'];
        $this->removed[] = I::NM_VALET;
    }

    private function decideResolved(): void
    {
        $unit = $this->unit('systemd-resolved.service');
        // Socket activation can bring resolved back after Valet disabled it,
        // so "active" alone does not mean its enablement was restored.
        if ($unit['load'] !== 'loaded' || ($unit['active'] === 'active' && $unit['enabled'] !== 'disabled')) {
            $this->plan->resolver = $unit['active'] === 'active' ? 'systemd-resolved' : 'network configuration';

            return;
        }
        if ($unit['enabled'] === 'masked') {
            $this->plan->resolver = 'network configuration';
            $this->plan->evidence[] = 'systemd-resolved is masked; Valet never masked it, so it is left alone.';

            return;
        }

        // 1.6.1+ never touched /etc/resolv.conf; the watcher kept the original in the backup.
        $original = $this->resolvIntoWatcherDir() ? I::RESOLV_BACKUP : I::RESOLV;
        $node = $this->node($original);
        $target = $node !== null && $node['type'] === 'link'
            ? System::absoluteTarget($original, (string) $node['target'])
            : null;

        if ($target !== null && in_array($target, self::RESOLVED_FILES, true)) {
            $evidence = sprintf('%s points to %s, so systemd-resolved was the resolver before Valet disabled it', $original, $target);
            $default = 'restore';
        } elseif ($unit['preset'] === 'enabled') {
            $evidence = 'the distribution enables systemd-resolved by default, but resolv.conf does not show whether it was used';
            $default = null;
        } else {
            $evidence = 'nothing shows systemd-resolved was in use before Valet';
            $default = 'leave';
        }
        $this->plan->decisions['resolved'] = [
            'question' => 'Re-enable systemd-resolved, which the legacy installer disabled?',
            'options' => ['restore', 'leave'],
            'default' => $default,
            'chosen' => $default,
            'evidence' => $evidence,
        ];
    }

    private function decideDnsmasq(): void
    {
        $unit = $this->unit('dnsmasq.service');
        if ($unit['load'] !== 'loaded') {
            return;
        }

        $remaining = $this->remainingDnsmasqConfig();
        if ($remaining === []) {
            $default = 'disable';
            $evidence = 'without Valet\'s files the system dnsmasq has no configuration of its own';
        } else {
            $default = 'keep';
            $evidence = 'non-Valet dnsmasq settings remain: ' . implode(', ', array_slice($remaining, 0, 4));
        }
        $this->plan->decisions['dnsmasq'] = [
            'question' => 'Stop and disable the system dnsmasq service that Valet enabled?',
            'options' => ['disable', 'keep'],
            'default' => $default,
            'chosen' => $default,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param array<string, string> $choices
     */
    private function applyChoices(array $choices): void
    {
        foreach ($choices as $key => $value) {
            if (!isset($this->plan->decisions[$key])) {
                continue;
            }
            if (!in_array($value, $this->plan->decisions[$key]['options'], true)) {
                $this->plan->conflicts[] = sprintf('Invalid value "%s" for --%s.', $value, $key);
                continue;
            }
            $this->plan->decisions[$key]['chosen'] = $value;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolverSteps(): array
    {
        $steps = [];
        $dnsmasq = $this->plan->decisions['dnsmasq']['chosen'] ?? null;
        $resolved = $this->plan->decisions['resolved']['chosen'] ?? null;
        $dnsmasqUnit = $this->unit('dnsmasq.service');
        $resolvedServes = $resolved === 'restore' || $this->unit('systemd-resolved.service')['active'] === 'active';

        if ($dnsmasq === 'disable') {
            $steps[] = ['op' => 'unit', 'unit' => 'dnsmasq.service', 'enable' => false, 'action' => 'stop',
                'reason' => 'it only served Valet'];
        } elseif ($dnsmasq === 'keep' && $dnsmasqUnit['active'] === 'active') {
            $steps[] = ['op' => 'unit', 'unit' => 'dnsmasq.service', 'enable' => null, 'action' => 'restart',
                'reason' => 'drop the removed Valet settings'];
            if ($resolvedServes && !$this->dnsmasqBindsNarrowly()) {
                $this->plan->conflicts[] = 'The remaining dnsmasq configuration listens on every address and would block '
                    . 'systemd-resolved on 127.0.0.53:53. Add bind-interfaces with a listen-address, or pass --dnsmasq=disable.';
            }
        }

        // A resolved that started while the legacy dnsmasq held port 53 runs
        // without its stub listener; restart it once the port is free.
        $resolvedUnit = $this->unit('systemd-resolved.service');
        $portFreed = $dnsmasq === 'disable' && in_array($dnsmasqUnit['active'], ['active', 'activating', 'failed'], true);
        if ($resolved === 'restore') {
            $steps[] = ['op' => 'unit', 'unit' => 'systemd-resolved.service', 'enable' => true,
                'action' => $resolvedUnit['active'] === 'active' ? ($portFreed ? 'restart' : null) : 'start',
                'reason' => 'restore the pre-Valet resolver'];
            $this->plan->resolver = 'systemd-resolved';
        } elseif ($resolvedUnit['active'] === 'active' && $portFreed) {
            $steps[] = ['op' => 'unit', 'unit' => 'systemd-resolved.service', 'enable' => null, 'action' => 'restart',
                'reason' => 'it may have started without its 127.0.0.53 listener while dnsmasq held port 53'];
        }
        if ($resolved === 'leave') {
            $this->plan->resolver = $resolvedUnit['active'] === 'active'
                ? 'systemd-resolved (socket-activated, stays disabled)'
                : 'network configuration (systemd-resolved stays disabled)';
        }

        $nm = $this->unit('NetworkManager.service');
        if (in_array(I::NM_VALET, $this->removed, true) && $nm['active'] === 'active') {
            $steps[] = ['op' => 'unit', 'unit' => 'NetworkManager.service', 'enable' => null, 'action' => 'restart',
                'reason' => 'apply its default DNS handling'];
        }

        return $steps;
    }

    /**
     * @param list<array<string, mixed>> $cleanup
     */
    private function planWatcherDirectory(array &$cleanup): void
    {
        if ($this->node(I::WATCHER_DIR) === null) {
            return;
        }
        $kept = [];
        foreach ($this->facts['dirs'][I::WATCHER_DIR] ?? [] as $entry) {
            $path = I::WATCHER_DIR . '/' . $entry;
            $node = $this->node($path);
            $removable = match (true) {
                $entry === 'valet-dns' => Fingerprints::matches($node['content'] ?? null, Fingerprints::WATCHER_SCRIPT),
                $entry === 'custom-nameservers' => ($node['type'] ?? '') === 'file' && trim((string) $node['content']) === '',
                default => in_array($entry, Fingerprints::WATCHER_RUNTIME_FILES, true) && ($node['type'] ?? '') === 'file',
            };
            if ($removable) {
                $cleanup[] = ['op' => 'file-remove', 'path' => $path, 'reason' => 'legacy watcher file'];
            } else {
                $kept[] = $entry;
            }
        }
        if ($kept === []) {
            $cleanup[] = ['op' => 'dir-remove', 'path' => I::WATCHER_DIR];
        } else {
            $this->plan->warnings[] = sprintf('%s is kept because it contains unexpected or edited files: %s', I::WATCHER_DIR, implode(', ', $kept));
        }
    }

    private function residueNotes(): void
    {
        $resolv = $this->node(I::RESOLV);
        $resolved = $this->unit('systemd-resolved.service');
        if ($resolv !== null && $resolv['type'] === 'link'
            && System::absoluteTarget(I::RESOLV, (string) $resolv['target']) === '/run/systemd/resolve/resolv.conf'
            && $resolved['load'] === 'loaded' && $resolved['enabled'] === 'disabled') {
            $this->plan->manual[] = 'resolv.conf points to /run/systemd/resolve/resolv.conf while systemd-resolved is disabled. '
                . 'This matches the state left by the legacy "valet uninstall". If you used systemd-resolved before, run: '
                . 'sudo systemctl enable --now systemd-resolved && sudo ln -sf ../run/systemd/resolve/stub-resolv.conf /etc/resolv.conf';
        }
        foreach ([I::DNSMASQ_CONF_BACKUP, I::RESOLV_BACKUP, I::RESOLVED_CONF_BACKUP] as $path) {
            if ($this->node($path) !== null) {
                $this->plan->manual[] = $path . ' exists. It may be left from a legacy Valet install; it is not changed automatically.';
            }
        }
    }

    private function notes(): void
    {
        if ($this->node(I::RESOLVED_CONF_BACKUP) !== null) {
            $this->plan->manual[] = I::RESOLVED_CONF_BACKUP . ' exists and is not restored automatically; compare it with ' . I::RESOLVED_CONF . '.';
        }
        if (preg_match('/^\s*DNSStubListener\s*=\s*no/mi', (string) $this->content(I::RESOLVED_CONF))) {
            $this->plan->warnings[] = I::RESOLVED_CONF . ' disables the stub listener; Valet delegation needs it.';
        }
        if ($this->node(I::RC_LOCAL_BACKUP) !== null) {
            $this->plan->manual[] = I::RC_LOCAL_BACKUP . ' exists and is not restored automatically.';
        }
        $custom = $this->node(I::WATCHER_DIR . '/custom-nameservers');
        if ($custom !== null && trim((string) $custom['content']) !== '') {
            $this->plan->manual[] = 'Custom nameservers in ' . I::WATCHER_DIR . '/custom-nameservers are not carried over; '
                . 'configure them in NetworkManager or systemd-resolved if you still need them.';
        }
        foreach ($this->facts['dirs'][I::NM_DIR] ?? [] as $entry) {
            $path = I::NM_DIR . '/' . $entry;
            if ($path !== I::NM_VALET && preg_match('/^\s*dns\s*=/m', (string) $this->content($path))) {
                $this->plan->evidence[] = $path . ' sets a NetworkManager DNS mode and is kept.';
            }
        }
    }

    /**
     * Active dnsmasq settings left after the planned removals.
     *
     * @return list<string>
     */
    private function remainingDnsmasqConfig(): array
    {
        $settings = [];
        foreach ($this->remainingDnsmasqSources() as $path => $content) {
            if ($this->pristine($path, $content) === true) {
                continue;
            }
            foreach (preg_split('/\R/', (string) $content) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || str_starts_with($line, 'conf-dir=')) {
                    continue;
                }
                // The packaged NetworkManager snippet only narrows the listener.
                if ($path === I::DNSMASQ_NM && in_array($line, ['bind-interfaces', 'except-interface=lo'], true)) {
                    continue;
                }
                $settings[] = basename($path) . ': ' . $line;
            }
        }

        return $settings;
    }

    /**
     * dnsmasq configuration files as they will be after the planned changes:
     * dnsmasq.conf restored from its backup, Valet files removed.
     *
     * @return array<string, ?string> Content by path
     */
    private function remainingDnsmasqSources(): array
    {
        $sources = [I::DNSMASQ_CONF => $this->dnsmasqConf];
        foreach ($this->facts['dirs'][I::DNSMASQ_DIR] ?? [] as $entry) {
            $path = I::DNSMASQ_DIR . '/' . $entry;
            if (in_array($path, $this->removed, true) || $entry === 'README'
                || preg_match('/(\.dpkg-[a-z]+|\.rpm[a-z]+|~|\.bak)$/', $entry)) {
                continue;
            }
            $sources[$path] = $this->content($path);
        }

        return $sources;
    }

    private function dnsmasqBindsNarrowly(): bool
    {
        $all = implode("\n", array_map('strval', $this->remainingDnsmasqSources()));

        return (bool) preg_match('/^\s*bind-(interfaces|dynamic)\s*$/m', $all)
            && (bool) preg_match('/^\s*(listen-address|except-interface\s*=\s*lo)/m', $all);
    }

    private function resolvIntoWatcherDir(): bool
    {
        $node = $this->node(I::RESOLV);

        return $node !== null && $node['type'] === 'link'
            && str_starts_with(System::absoluteTarget(I::RESOLV, (string) $node['target']), I::WATCHER_DIR . '/');
    }

    /**
     * Whether the content matches the digest recorded by the owning package.
     */
    private function pristine(string $path, ?string $content): ?bool
    {
        $package = $this->facts['packages'][$path] ?? null;
        if (!is_array($package) || $package['digest'] === null || $content === null) {
            return null;
        }

        return hash((string) $package['algorithm'], $content) === $package['digest'];
    }

    /**
     * @param array<string, mixed> $node
     */
    private function describeNode(array $node, string $path): string
    {
        return $node['type'] === 'link'
            ? 'symlink to ' . System::absoluteTarget($path, (string) $node['target'])
            : $node['type'];
    }

    private function marker(string $marker): void
    {
        $this->plan->markers[] = $marker;
        $this->plan->evidence[] = $marker;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function node(string $path): ?array
    {
        $node = $this->facts['nodes'][$path] ?? null;

        return is_array($node) ? $node : null;
    }

    private function content(string $path): ?string
    {
        $node = $this->node($path);

        return $node !== null && $node['type'] === 'file' ? $node['content'] : null;
    }

    /**
     * @return array{load: string, active: string, enabled: string, preset: string}
     */
    private function unit(string $unit): array
    {
        return $this->facts['units'][$unit] ?? ['load' => 'not-found', 'active' => 'inactive', 'enabled' => '', 'preset' => ''];
    }
}
