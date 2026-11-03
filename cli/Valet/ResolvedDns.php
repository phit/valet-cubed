<?php

namespace Valet;

use RuntimeException;

/**
 * A private wildcard DNS server routed only through systemd's delegation.
 */
class ResolvedDns
{
    public const UNIT = '/etc/systemd/system/valet-linux-plus-dns.service';
    public const DELEGATE = '/etc/systemd/dns-delegate.d/valet-linux-plus.dns-delegate';
    private const MARKER = '# Managed by Valet Linux+';
    private const SERVICE = 'valet-linux-plus-dns.service';

    public function __construct(private Filesystem $files, private CommandLine $cli)
    {
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

        $binary = $this->binary();
        $unit = self::MARKER . "\n[Unit]\nDescription=Valet Linux+ local wildcard DNS\nAfter=network.target\n\n"
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
            $probe = 'valet-check-' . bin2hex(random_bytes(8)) . '.' . $domain;
            $answer = $this->command('resolvectl query --type=A --cache=no ' . escapeshellarg($probe));
            if (!preg_match('/(?<![0-9.])127\.0\.0\.1(?![0-9.])/', $answer)) {
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
        $delegateOwned = $this->owned(self::DELEGATE);
        $unitOwned = $this->owned(self::UNIT);
        if ($delegateOwned) {
            $this->files->unlink(self::DELEGATE);
            if (trim($this->cli->run('systemctl is-active systemd-resolved.service 2>/dev/null')) === 'active') {
                $this->command('systemctl reload systemd-resolved.service');
            }
        }
        if ($unitOwned) {
            $this->cli->run('systemctl disable --now ' . self::SERVICE);
            $this->files->unlink(self::UNIT);
            $this->cli->run('systemctl daemon-reload');
        }
    }

    private function owned(string $path): bool
    {
        return $this->files->exists($path) && str_starts_with($this->files->get($path), self::MARKER . "\n");
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
