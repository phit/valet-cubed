<?php

namespace Valet;

use RuntimeException;

/**
 * A private wildcard DNS server routed only through systemd's delegation.
 */
class ResolvedDns
{
    public const UNIT = '/etc/systemd/system/valet-cubed-dns.service';
    public const DELEGATE = '/etc/systemd/dns-delegate.d/valet-cubed.dns-delegate';
    public const SERVICE = 'valet-cubed-dns.service';
    private const MARKER = '# Managed by Valet Cubed';

    /**
     * Names used before the rename to Valet Cubed; removed when found.
     */
    public const FORMER_UNIT = '/etc/systemd/system/valet-linux-plus-dns.service';
    public const FORMER_DELEGATE = '/etc/systemd/dns-delegate.d/valet-linux-plus.dns-delegate';
    public const FORMER_SERVICE = 'valet-linux-plus-dns.service';
    private const FORMER_MARKER = '# Managed by Valet Linux+';

    /**
     * @var callable(int): void
     */
    private $sleep;

    /**
     * @param (callable(int): void)|null $sleep Receives microseconds; injectable for tests
     */
    public function __construct(private Filesystem $files, private CommandLine $cli, ?callable $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function available(): bool
    {
        $version = $this->cli->run('systemctl --version 2>/dev/null');
        if (!preg_match('/^systemd\s+(\d+)/', $version, $matches) || (int) $matches[1] < 258) {
            return false;
        }

        if (!$this->files->exists('/etc/resolv.conf')) {
            return false;
        }
        $resolver = $this->files->realpath('/etc/resolv.conf');
        if (!in_array($resolver, ['/run/systemd/resolve/stub-resolv.conf', '/usr/lib/systemd/resolv.conf'], true)) {
            return false;
        }

        return trim($this->cli->run('systemctl is-active systemd-resolved.service 2>/dev/null')) === 'active'
            && $this->binary() !== null;
    }

    public function activate(string $domain): bool
    {
        if (!$this->available() || !$this->validDomain($domain)) {
            return false;
        }

        // The former service listens on the same address and port.
        $this->removeFormerNames();

        $binary = $this->binary();
        $unit = self::MARKER . "\n[Unit]\nDescription=Valet Cubed local wildcard DNS\nAfter=network.target\n\n"
            . "[Service]\nType=simple\nDynamicUser=yes\nExecStart=" . $binary
            . ' --keep-in-foreground --conf-file=/dev/null --no-resolv --no-hosts --bind-interfaces'
            . ' --listen-address=127.0.0.2 --port=53535 --local=/' . $domain . '/ --address=/' . $domain . "/127.0.0.1\n"
            . "Restart=on-failure\n\n[Install]\nWantedBy=multi-user.target\n";
        $delegate = self::MARKER . "\n[Delegate]\nDNS=127.0.0.2:53535\nDomains=~" . $domain . "\nDefaultRoute=no\n";

        foreach ([self::UNIT, self::DELEGATE] as $path) {
            if ($this->files->exists($path) && !str_starts_with($this->files->get($path), self::MARKER . "\n")) {
                return false;
            }
        }

        $unitChanged = !$this->files->exists(self::UNIT) || $this->files->get(self::UNIT) !== $unit;
        $delegateChanged = !$this->files->exists(self::DELEGATE) || $this->files->get(self::DELEGATE) !== $delegate;
        try {
            if ($unitChanged) {
                $this->files->put(self::UNIT, $unit);
                if ($this->files->get(self::UNIT) !== $unit) {
                    throw new RuntimeException('Unable to write the resolver service.');
                }
                $this->command('systemctl daemon-reload');
                $this->command('systemctl enable ' . self::SERVICE);
                $this->command('systemctl restart ' . self::SERVICE);
            } else {
                $this->command('systemctl start ' . self::SERVICE);
            }
            if ($delegateChanged) {
                $this->files->ensureDirExists(dirname(self::DELEGATE));
                $this->files->put(self::DELEGATE, $delegate);
                if ($this->files->get(self::DELEGATE) !== $delegate) {
                    throw new RuntimeException('Unable to write the DNS delegation.');
                }
                $this->command('systemctl reload systemd-resolved.service');
            }
            if (!$this->delegationAnswers($domain)) {
                throw new RuntimeException('The delegated resolver did not return the local address.');
            }
        } catch (\Throwable $exception) {
            $this->deactivate();
            return false;
        }

        return true;
    }

    public function deactivate(): void
    {
        $this->remove(self::UNIT, self::DELEGATE, self::SERVICE, self::MARKER);
        $this->removeFormerNames();
    }

    private function removeFormerNames(): void
    {
        $this->remove(self::FORMER_UNIT, self::FORMER_DELEGATE, self::FORMER_SERVICE, self::FORMER_MARKER);
    }

    private function remove(string $unit, string $delegate, string $service, string $marker): void
    {
        $delegateOwned = $this->owned($delegate, $marker);
        $unitOwned = $this->owned($unit, $marker);
        if ($delegateOwned) {
            $this->files->unlink($delegate);
            if (trim($this->cli->run('systemctl is-active systemd-resolved.service 2>/dev/null')) === 'active') {
                $this->command('systemctl reload systemd-resolved.service');
            }
        }
        if ($unitOwned) {
            $this->cli->run('systemctl disable --now ' . $service);
            $this->files->unlink($unit);
            $this->cli->run('systemctl daemon-reload');
        }
    }

    /**
     * Probe the delegation. resolved applies a reload asynchronously, so the
     * first queries after a reload can still miss the new delegation.
     */
    private function delegationAnswers(string $domain): bool
    {
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $probe = 'valet-check-' . bin2hex(random_bytes(8)) . '.' . $domain;
            $answer = $this->cli->run('resolvectl query --type=A --cache=no ' . escapeshellarg($probe) . ' 2>&1');
            if (preg_match('/(?<![0-9.])127\.0\.0\.1(?![0-9.])/', $answer)) {
                return true;
            }
            ($this->sleep)(250000);
        }

        return false;
    }

    private function owned(string $path, string $marker): bool
    {
        return $this->files->exists($path) && str_starts_with($this->files->get($path), $marker . "\n");
    }

    private function binary(): ?string
    {
        $binary = trim($this->cli->run('command -v dnsmasq 2>/dev/null'));
        return preg_match('~\A/[a-zA-Z0-9_./+-]+\z~', $binary) ? $binary : null;
    }

    private function validDomain(string $domain): bool
    {
        return !in_array(strtolower($domain), ['local', 'localhost'], true)
            && strlen($domain) <= 253
            && (bool) preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/', $domain);
    }

    private function command(string $command): string
    {
        return $this->cli->run($command, static function ($status, $output) {
            throw new RuntimeException('Resolver setup failed: ' . $output);
        });
    }
}
