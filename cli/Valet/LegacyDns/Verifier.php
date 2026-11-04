<?php

namespace Valet\LegacyDns;

use Valet\Hosts;
use Valet\ResolvedDns;

/**
 * Checks name resolution through NSS, the path normal applications use.
 */
class Verifier
{
    public const DEFAULT_PROBES = ['example.com'];

    /**
     * @var callable(int): void
     */
    private $sleep;

    /**
     * @param (callable(int): void)|null $sleep
     */
    public function __construct(private System $system, ?callable $sleep = null, private int $attempts = 15)
    {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param list<string> $names
     * @return array<string, list<string>|null> Addresses per name, null when unresolved
     */
    public function probe(array $names): array
    {
        $results = [];
        foreach ($names as $name) {
            $results[$name] = $this->lookup($name);
        }

        return $results;
    }

    /**
     * Names that resolved before must still resolve. Waits for resolvers that
     * are still starting.
     *
     * @param array<string, list<string>|null> $before
     * @return list<string> Failures
     */
    public function compare(array $before): array
    {
        $pending = array_keys(array_filter($before, fn ($addresses) => $addresses !== null));
        for ($attempt = 1; $pending !== []; $attempt++) {
            $pending = array_values(array_filter($pending, fn ($name) => $this->lookup((string) $name) === null));
            if ($pending === [] || $attempt >= $this->attempts) {
                break;
            }
            ($this->sleep)(1);
        }

        return array_map(fn ($name) => sprintf('%s resolved before the change but no longer resolves.', $name), $pending);
    }

    /**
     * Check that the resolver the plan expects is actually serving DNS.
     *
     * @return list<string> Failures
     */
    public function resolver(string $expected): array
    {
        if ($expected !== 'systemd-resolved') {
            return [];
        }
        $failures = [];
        if ($this->system->unit('systemd-resolved.service')['active'] !== 'active') {
            $failures[] = 'systemd-resolved is not active.';
        }
        $resolv = $this->system->resolveLink(Inspector::RESOLV);
        if (!str_starts_with($resolv, '/run/systemd/resolve/') && !str_ends_with($resolv, '/systemd/resolv.conf')) {
            $failures[] = Inspector::RESOLV . ' does not use systemd-resolved (' . $resolv . ').';
        }
        // NSS can reach resolved without the stub, but programs that read
        // resolv.conf themselves (dig, Go, browsers, containers) cannot.
        if ($resolv === '/run/systemd/resolve/stub-resolv.conf' && $this->system->listening('127.0.0.53', 53) === false) {
            $failures[] = 'systemd-resolved is not listening on 127.0.0.53:53.';
        }

        return $failures;
    }

    /**
     * Check that a Valet hostname resolves locally after configuration.
     *
     * @return list<string> Failures
     */
    public function valet(string $domain): array
    {
        $name = $this->valetProbe($domain);
        if ($name === null) {
            return [];
        }
        for ($attempt = 1; ; $attempt++) {
            $addresses = $this->lookup($name) ?? [];
            if (in_array('127.0.0.1', $addresses, true)) {
                return [];
            }
            if ($attempt >= $this->attempts) {
                return [sprintf('%s does not resolve to 127.0.0.1.', $name)];
            }
            ($this->sleep)(1);
        }
    }

    /**
     * Nameservers the system resolver forwards to.
     *
     * @return list<string>
     */
    public function upstreams(): array
    {
        if ($this->system->unit('systemd-resolved.service')['active'] === 'active') {
            [, $output] = $this->system->run('resolvectl dns 2>/dev/null');
        } else {
            $node = $this->system->node($this->system->resolveLink(Inspector::RESOLV));
            $output = (string) ($node['content'] ?? '');
            $output = implode("\n", preg_grep('/^\s*nameserver\s/', preg_split('/\R/', $output) ?: []) ?: []);
        }
        preg_match_all('/(?<![\w.:])(\d{1,3}(?:\.\d{1,3}){3}|[0-9a-f]*:[0-9a-f:]+)(?![\w.:])/i', $output, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function valetProbe(string $domain): ?string
    {
        if ($this->system->node(ResolvedDns::DELEGATE) !== null) {
            return 'valet-migration-' . bin2hex(random_bytes(4)) . '.' . $domain;
        }
        $hosts = (string) ($this->system->node(Inspector::HOSTS)['content'] ?? '');
        $start = preg_quote(Hosts::START_MARKER, '/');
        if (preg_match('/' . $start . '\n(?:#[^\n]*\n)*127\.0\.0\.1\t(\S+)\n/', $hosts, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function lookup(string $name): ?array
    {
        [$status, $output] = $this->system->run('getent ahosts ' . escapeshellarg($name) . ' 2>/dev/null');
        if ($status !== 0) {
            return null;
        }
        $addresses = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $address = strtok($line, " \t");
            if ($address !== false && $address !== '') {
                $addresses[] = $address;
            }
        }

        return $addresses === [] ? null : array_values(array_unique($addresses));
    }
}
