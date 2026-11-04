<?php

namespace Valet\LegacyDns;

use RuntimeException;
use Throwable;
use Valet\ResolvedDns;

/**
 * Applies a migration plan step by step, recording a snapshot before each
 * change so that any failure, interruption or later request can restore the
 * previous state.
 */
class Migrator
{
    /**
     * Only these paths may ever be written during apply or rollback.
     */
    private const MANAGED_PATHS = [
        Inspector::RESOLV, Inspector::RESOLV_BACKUP, Inspector::DNSMASQ_CONF, Inspector::DNSMASQ_CONF_BACKUP,
        Inspector::DNSMASQ_OPTIONS, Inspector::DNSMASQ_VALET, Inspector::DNSMASQ_NM, Inspector::DNSMASQ_DEFAULT,
        Inspector::NM_VALET, Inspector::WATCHER_DIR, Inspector::WATCHER_UNIT, Inspector::WATCHER_SYSV,
        Inspector::HOSTS, ResolvedDns::UNIT, ResolvedDns::DELEGATE, ResolvedDns::FORMER_UNIT, ResolvedDns::FORMER_DELEGATE,
    ];

    private const BACKEND_UNITS = [ResolvedDns::SERVICE, ResolvedDns::FORMER_SERVICE, 'systemd-resolved.service'];

    /**
     * @var callable(string): void
     */
    private $backend;

    /**
     * @var list<string>
     */
    private array $log = [];

    /**
     * @var (callable(string): void)|null
     */
    private $output;

    private bool $interrupted = false;

    /**
     * @param callable(string): void      $backend Configures Valet resolution for a domain
     * @param (callable(string): void)|null $output  Receives progress messages
     */
    public function __construct(
        private System $system,
        private Journal $journal,
        private Verifier $verifier,
        callable $backend,
        ?callable $output = null
    ) {
        $this->backend = $backend;
        $this->output = $output;
    }

    /**
     * @param list<string> $probes
     * @return array{status: string, id: ?string, log: list<string>}
     */
    public function apply(Plan $plan, array $probes): array
    {
        if ($plan->blocked()) {
            throw new RuntimeException('The migration plan is blocked; resolve its conflicts and decisions first.');
        }
        $this->log = [];
        $this->journal->lock();
        try {
            $this->refuseUnfinished();
            $before = $this->verifier->probe($probes);
            $this->say('Lookups before migration: ' . $this->describeProbes($before));

            $journal = [
                'id' => '',
                'created' => gmdate('c'),
                'status' => 'applying',
                'plan' => $plan->toArray(),
                'probes' => $before,
                'steps' => [],
                'warnings' => [],
            ];
            $id = $this->journal->create($journal);
            $journal['id'] = $id;
            $this->say('Journal: ' . $this->journal->file($id));

            $this->trapSignals();
            try {
                foreach ($plan->steps as $step) {
                    if ($step['op'] === 'valet-backend') {
                        $this->verifyBase($plan, $before, $journal);
                    }
                    $this->runStep($step, $journal);
                    if ($this->interrupted) {
                        throw new RuntimeException('Interrupted.');
                    }
                }
                if (!in_array('valet-backend', array_column($plan->steps, 'op'), true)) {
                    $this->verifyBase($plan, $before, $journal);
                }
                foreach ($plan->steps as $step) {
                    if ($step['op'] === 'valet-backend') {
                        $failures = $this->verifier->valet((string) $step['domain']);
                        if ($failures !== []) {
                            throw new RuntimeException(implode(' ', $failures));
                        }
                        $this->say('Valet resolution verified for .' . $step['domain']);
                    }
                }
            } catch (Throwable $exception) {
                $this->say('Migration failed: ' . $exception->getMessage());
                $journal['status'] = 'failed';
                $journal['error'] = $exception->getMessage();
                $this->journal->save($journal);
                $status = $this->restore($journal);

                return ['status' => $status, 'id' => $id, 'log' => $this->log];
            } finally {
                $this->releaseSignals();
            }

            $upstreams = $this->verifier->upstreams();
            $this->say('Upstream DNS servers now: ' . ($upstreams === [] ? 'none reported' : implode(', ', $upstreams)));
            if (array_intersect($upstreams, Fingerprints::PUBLIC_UPSTREAMS) !== []) {
                $journal['warnings'][] = 'The resolver still uses 1.1.1.1 or 8.8.8.8. Check that this comes from your network configuration and not a leftover Valet setting.';
                $this->say(end($journal['warnings']));
            }
            $journal['status'] = 'completed';
            $journal['completed'] = gmdate('c');
            $this->journal->save($journal);
            $this->say('Migration completed.');

            return ['status' => 'completed', 'id' => $id, 'log' => $this->log];
        } finally {
            $this->journal->unlock();
        }
    }

    /**
     * Roll back a migration recorded in the journal.
     *
     * @return array{status: string, id: ?string, log: list<string>}
     */
    public function rollback(?string $id = null): array
    {
        $this->log = [];
        $this->journal->lock();
        try {
            $journal = $id === null ? $this->journal->latest() : $this->journal->load($id);
            if ($journal === null) {
                throw new RuntimeException('No DNS migration journal found.');
            }
            if ($journal['status'] === 'rolled-back') {
                throw new RuntimeException('Migration ' . $journal['id'] . ' was already rolled back.');
            }
            $this->say('Rolling back migration ' . $journal['id']);

            return ['status' => $this->restore($journal), 'id' => (string) $journal['id'], 'log' => $this->log];
        } finally {
            $this->journal->unlock();
        }
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $journal
     */
    private function runStep(array $step, array &$journal): void
    {
        $paths = $this->paths($step);
        foreach ($paths as $path) {
            $this->assertManaged($path);
        }
        $entry = [
            'step' => $step,
            'state' => 'applying',
            'files' => [],
            'units' => [],
        ];
        unset($entry['step']['content']);
        foreach ($paths as $path) {
            $entry['files'][$path] = $this->snapshot($path);
        }
        foreach ($this->units($step) as $unit) {
            $entry['units'][$unit] = $this->system->unit($unit);
        }
        $journal['steps'][] = $entry;
        $index = array_key_last($journal['steps']);
        $this->journal->save($journal);

        $this->say(Plan::stepSummary($step));
        try {
            $this->execute($step);
        } catch (Throwable $exception) {
            if (empty($step['optional'])) {
                throw $exception;
            }
            $journal['steps'][$index]['state'] = 'skipped';
            $journal['warnings'][] = Plan::stepSummary($step) . ' was skipped: ' . $exception->getMessage();
            $this->say('  skipped: ' . $exception->getMessage());
            $this->journal->save($journal);

            return;
        }
        $journal['steps'][$index]['state'] = 'done';
        $this->journal->save($journal);
    }

    /**
     * @param array<string, mixed> $step
     */
    private function execute(array $step): void
    {
        switch ($step['op']) {
            case 'file-remove':
            case 'dir-remove':
                $this->system->remove((string) $step['path']);
                break;
            case 'file-restore':
                $this->system->rename((string) $step['from'], (string) $step['to']);
                break;
            case 'file-write':
                $this->system->writeFile((string) $step['path'], (string) $step['content'], (int) $step['mode'], (int) $step['uid'], (int) $step['gid']);
                break;
            case 'symlink':
                $this->system->symlink((string) $step['path'], (string) $step['target'], 0, 0);
                break;
            case 'immutable':
                $this->system->setImmutable((string) $step['path'], (bool) $step['value']);
                break;
            case 'daemon-reload':
                $this->system->mustRun('systemctl daemon-reload');
                break;
            case 'command':
                $this->system->mustRun((string) $step['run']);
                break;
            case 'unit':
                $unit = escapeshellarg((string) $step['unit']);
                if ($step['enable'] !== null) {
                    $this->system->mustRun('systemctl ' . ($step['enable'] ? 'enable' : 'disable') . ' ' . $unit);
                }
                if ($step['action'] !== null) {
                    $this->system->mustRun('systemctl ' . $step['action'] . ' ' . $unit);
                }
                if (in_array($step['action'], ['start', 'restart'], true)) {
                    $this->waitActive((string) $step['unit']);
                }
                break;
            case 'package-file':
                $this->restorePackageFile($step);
                break;
            case 'valet-backend':
                ($this->backend)((string) $step['domain']);
                break;
            default:
                throw new RuntimeException('Unknown migration step ' . $step['op']);
        }
    }

    /**
     * Restore files in reverse order, then unit states in reverse order,
     * so services restart against their restored configuration.
     *
     * @param array<string, mixed> $journal
     */
    private function restore(array $journal): string
    {
        $journal['status'] = 'rolling-back';
        $this->journal->save($journal);
        $errors = [];
        $steps = array_reverse($journal['steps'], true);

        // Units created by the migration must be stopped while their files still exist.
        foreach ($steps as $entry) {
            foreach ($entry['units'] as $unit => $state) {
                if ($state['load'] !== 'loaded' && $this->system->unit((string) $unit)['load'] === 'loaded') {
                    $this->system->run('systemctl disable --now ' . escapeshellarg((string) $unit));
                }
            }
        }

        foreach ($steps as $entry) {
            if ($entry['state'] === 'skipped') {
                continue;
            }
            foreach (array_reverse($entry['files'], true) as $path => $snapshot) {
                try {
                    $this->assertManaged((string) $path);
                    $this->restoreSnapshot((string) $path, $snapshot);
                } catch (Throwable $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
        }

        $this->system->run('systemctl daemon-reload');
        foreach ($steps as $entry) {
            if ($entry['state'] === 'skipped') {
                continue;
            }
            if ($entry['step']['op'] === 'command' && $entry['state'] !== 'applying') {
                [$status, $output] = $this->system->run((string) $entry['step']['undo']);
                if ($status !== 0) {
                    $errors[] = 'Undo failed: ' . $entry['step']['undo'] . ' ' . trim($output);
                }
            }
        }
        $errors = array_merge($errors, $this->restoreUnits($journal['steps']));

        $failures = $this->verifier->compare($journal['probes'] ?? []);
        foreach (array_merge($errors, $failures) as $message) {
            $this->say('Rollback problem: ' . $message);
        }
        $journal['status'] = $errors === [] && $failures === [] ? 'rolled-back' : 'rollback-incomplete';
        $journal['rollback'] = ['finished' => gmdate('c'), 'errors' => $errors, 'verification' => $failures];
        $this->journal->save($journal);
        $this->say($journal['status'] === 'rolled-back'
            ? 'The previous DNS configuration was restored and verified.'
            : 'Rollback finished with problems. The journal ' . $this->journal->file((string) $journal['id']) . ' lists the recorded state.');

        return (string) $journal['status'];
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    private function restoreSnapshot(string $path, ?array $snapshot): void
    {
        $current = $this->system->node($path);
        if ($current !== null && $current['immutable']) {
            $this->system->setImmutable($path, false);
        }
        if ($snapshot === null) {
            if ($current !== null) {
                $this->system->remove($path);
            }

            return;
        }
        switch ($snapshot['type']) {
            case 'file':
                $content = base64_decode((string) $snapshot['content'], true);
                if ($content === false) {
                    throw new RuntimeException('Corrupt snapshot for ' . $path);
                }
                $this->system->writeFile($path, $content, (int) $snapshot['mode'], (int) $snapshot['uid'], (int) $snapshot['gid']);
                if ($snapshot['immutable']) {
                    $this->system->setImmutable($path, true);
                }
                break;
            case 'link':
                if ($current !== null && $current['type'] === 'dir') {
                    throw new RuntimeException('Cannot restore the symlink ' . $path . ' over a directory.');
                }
                $this->system->symlink($path, (string) $snapshot['target'], (int) $snapshot['uid'], (int) $snapshot['gid']);
                break;
            case 'dir':
                $this->system->mkdir($path, (int) $snapshot['mode'], (int) $snapshot['uid'], (int) $snapshot['gid']);
                break;
            default:
                throw new RuntimeException('Cannot restore ' . $path);
        }
    }

    /**
     * Return every unit the migration touched to its state before the first
     * change: stop them all, then start the ones that were running in their
     * original order. Reversing each step instead can fail when services
     * compete for the same port (a legacy dnsmasq on every address and
     * systemd-resolved on 127.0.0.53), and the pre-migration state may depend
     * on which one started first.
     *
     * @param list<array<string, mixed>> $entries
     * @return list<string>
     */
    private function restoreUnits(array $entries): array
    {
        $original = [];
        foreach ($entries as $entry) {
            if ($entry['state'] === 'skipped') {
                continue;
            }
            foreach ($entry['units'] as $unit => $state) {
                if ($state['load'] === 'loaded') {
                    $original[(string) $unit] ??= $state;
                }
            }
        }

        $errors = [];
        $run = function (string $command) use (&$errors): void {
            [$status, $output] = $this->system->run($command);
            if ($status !== 0) {
                $errors[] = trim($command . ': ' . $output);
            }
        };
        foreach ($original as $unit => $state) {
            if (in_array($state['enabled'], ['enabled', 'disabled'], true)) {
                $run('systemctl ' . ($state['enabled'] === 'enabled' ? 'enable' : 'disable') . ' ' . escapeshellarg($unit));
            }
        }
        // Stop everything first so no restored service finds its port taken.
        foreach (array_reverse($original, true) as $unit => $state) {
            $run('systemctl stop ' . escapeshellarg($unit));
        }
        foreach ($original as $unit => $state) {
            if ($state['active'] === 'active') {
                $run('systemctl restart ' . escapeshellarg($unit));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, list<string>|null> $before
     * @param array<string, mixed>             $journal
     */
    private function verifyBase(Plan $plan, array $before, array &$journal): void
    {
        $failures = array_merge($this->verifier->resolver($plan->resolver), $this->verifier->compare($before));
        if ($failures !== []) {
            throw new RuntimeException(implode(' ', $failures));
        }
        $this->say('Normal DNS verified.');
        $journal['verified'] = gmdate('c');
        $this->journal->save($journal);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshot(string $path): ?array
    {
        $node = $this->system->node($path);
        if ($node === null) {
            return null;
        }
        if ($node['type'] === 'file' && $node['content'] === null) {
            throw new RuntimeException($path . ' is too large to snapshot.');
        }
        if ($node['type'] === 'other') {
            throw new RuntimeException($path . ' has an unsupported file type.');
        }

        return [
            'type' => $node['type'],
            'mode' => $node['mode'],
            'uid' => $node['uid'],
            'gid' => $node['gid'],
            'target' => $node['target'],
            'content' => $node['content'] === null ? null : base64_encode($node['content']),
            'immutable' => $node['immutable'],
        ];
    }

    /**
     * @param array<string, mixed> $step
     * @return list<string>
     */
    private function paths(array $step): array
    {
        return match ($step['op']) {
            'file-restore' => [(string) $step['from'], (string) $step['to']],
            'file-remove', 'dir-remove', 'file-write', 'symlink', 'immutable', 'package-file' => [(string) $step['path']],
            'command' => [Inspector::WATCHER_SYSV],
            'valet-backend' => [
                Inspector::HOSTS, ResolvedDns::UNIT, ResolvedDns::DELEGATE, ResolvedDns::FORMER_UNIT, ResolvedDns::FORMER_DELEGATE,
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $step
     * @return list<string>
     */
    private function units(array $step): array
    {
        return match ($step['op']) {
            'unit' => [(string) $step['unit']],
            'valet-backend' => self::BACKEND_UNITS,
            default => [],
        };
    }

    private function assertManaged(string $path): void
    {
        foreach (self::MANAGED_PATHS as $managed) {
            if ($path === $managed || ($managed === Inspector::WATCHER_DIR && str_starts_with($path, $managed . '/')
                && !str_contains(substr($path, strlen($managed) + 1), '/'))) {
                return;
            }
        }
        throw new RuntimeException('Refusing to modify unmanaged path ' . $path);
    }

    private function refuseUnfinished(): void
    {
        $latest = $this->journal->latest();
        if ($latest !== null && !in_array($latest['status'], ['completed', 'rolled-back'], true)) {
            throw new RuntimeException(sprintf(
                'Migration %s did not finish (status: %s). Run "valet dns:rollback" first.',
                $latest['id'],
                $latest['status']
            ));
        }
    }

    private function waitActive(string $unit): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $state = $this->system->unit($unit)['active'];
            if ($state === 'active') {
                return;
            }
            if ($state === 'failed') {
                break;
            }
            usleep(250000);
        }
        throw new RuntimeException($unit . ' did not become active.');
    }

    /**
     * Extract a pristine conffile from the installed package version and
     * verify it against the digest dpkg recorded at install time.
     *
     * @param array<string, mixed> $step
     */
    private function restorePackageFile(array $step): void
    {
        $package = (string) $step['package'];
        if (!preg_match('/\A[a-z0-9][a-z0-9.+-]*\z/', $package) || $step['algorithm'] !== 'md5') {
            throw new RuntimeException('Only dpkg conffiles can be restored automatically.');
        }
        $version = trim($this->system->mustRun('dpkg-query -W -f=\'${Version}\' ' . escapeshellarg($package)));
        $directory = sys_get_temp_dir() . '/valet-package-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create a temporary directory.');
        }
        try {
            $this->system->mustRun(sprintf(
                'cd %s && apt-get download -q %s 2>&1',
                escapeshellarg($directory),
                escapeshellarg($package . '=' . $version)
            ));
            $archives = glob($directory . '/*.deb') ?: [];
            if (count($archives) !== 1) {
                throw new RuntimeException('Package download did not produce one archive.');
            }
            $content = $this->system->mustRun(sprintf(
                'dpkg-deb --fsys-tarfile %s | tar -xOf - %s',
                escapeshellarg($archives[0]),
                escapeshellarg('.' . $step['path'])
            ));
            if (md5($content) !== $step['digest']) {
                throw new RuntimeException('The packaged file does not match the recorded checksum.');
            }
            $parent = $this->system->node(dirname((string) $step['path']));
            $this->system->writeFile((string) $step['path'], $content, 0644, (int) ($parent['uid'] ?? 0), (int) ($parent['gid'] ?? 0));
        } finally {
            array_map('unlink', glob($directory . '/*') ?: []);
            @rmdir($directory);
        }
    }

    /**
     * @param array<string, list<string>|null> $probes
     */
    private function describeProbes(array $probes): string
    {
        if ($probes === []) {
            return 'none';
        }
        $parts = [];
        foreach ($probes as $name => $addresses) {
            $parts[] = $name . ($addresses === null ? ' (unresolved)' : ' (ok)');
        }

        return implode(', ', $parts);
    }

    private function trapSignals(): void
    {
        $this->interrupted = false;
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, function () {
                $this->interrupted = true;
            });
        }
    }

    private function releaseSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
    }

    private function say(string $message): void
    {
        $this->log[] = $message;
        if ($this->output !== null) {
            ($this->output)($message);
        }
    }
}
