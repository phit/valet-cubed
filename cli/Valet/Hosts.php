<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Traits\Paths;

class Hosts
{
    use Paths;

    public PackageManager $pm;
    public ServiceManager $sm;
    public CommandLine $cli;
    public Filesystem $files;
    public Configuration $config;
    private ResolvedDns $resolvedDns;
    private bool $usingDelegatedDns = false;

    public string $hostsPath = '/etc/hosts';
    public const START_MARKER = '# Valet Cubed hosts start';
    public const END_MARKER = '# Valet Cubed hosts end';
    // Markers used before the rename to Valet Cubed; the block is removed when found.
    public const FORMER_START_MARKER = '# Valet Linux+ hosts start';
    public const FORMER_END_MARKER = '# Valet Linux+ hosts end';

    /**
     * Create a new Hosts instance.
     */
    public function __construct(
        PackageManager $pm,
        ServiceManager $sm,
        Filesystem $files,
        CommandLine $cli,
        Configuration $config,
        ?ResolvedDns $resolvedDns = null
    ) {
        $this->pm = $pm;
        $this->sm = $sm;
        $this->cli = $cli;
        $this->files = $files;
        $this->config = $config;
        $this->resolvedDns = $resolvedDns ?? new ResolvedDns($files, $cli);
    }

    /**
     * Install and configure Valet host entries.
     */
    public function install(string $domain): void
    {
        $this->updateDomain($domain);
        Writer::info($this->usingDelegatedDns
            ? 'Using systemd-resolved delegation for wildcard domains.'
            : 'Using /etc/hosts for local domains; wildcard subdomains are unavailable.');
    }

    /**
     * Remove Valet host entries.
     */
    public function stop(): void
    {
        $this->resolvedDns->deactivate();
        $this->removeValetHosts();
    }

    /**
     * Refresh Valet host entries.
     */
    public function restart(): void
    {
        $this->updateDomain($this->config->get('domain'));
    }

    /**
     * Refresh the Valet host entries for the given domain.
     */
    public function updateDomain(string $newDomain): void
    {
        if ($this->resolvedDns->activate($newDomain)) {
            $this->usingDelegatedDns = true;
            $this->removeValetHosts();
            return;
        }

        $this->usingDelegatedDns = false;
        $this->resolvedDns->deactivate();
        $hosts = $this->hostnames($newDomain);

        if (empty($hosts)) {
            $this->removeValetHosts();
            return;
        }

        $this->writeHostsBlock($hosts);
    }

    /**
     * Remove Valet host entries.
     */
    public function uninstall(): void
    {
        $this->resolvedDns->deactivate();
        $this->removeValetHosts();

        Writer::info('Valet host entries have been removed');
    }

    /**
     * Get the concrete Valet hostnames that /etc/hosts can represent.
     *
     * dnsmasq used to catch a wildcard domain. /etc/hosts cannot do that, so
     * Valet enumerates parked directories, linked sites, and Nginx configs.
     *
     * @return array<int, string>
     */
    private function hostnames(string $domain): array
    {
        $domain = trim($domain, '.');
        $hosts = [];

        /** @var array<int, string> $parkedPaths */
        $parkedPaths = $this->config->get('paths', []);
        foreach ($parkedPaths as $path) {
            if ($path === $this->sitesPath() || !$this->files->isDir($path)) {
                continue;
            }

            foreach ($this->files->scandir($path) as $site) {
                if ($this->files->isDir($path . '/' . $site)) {
                    $hosts[] = $this->domainHost($site, $domain);
                }
            }
        }

        if ($this->files->isDir($this->sitesPath())) {
            foreach ($this->files->scandir($this->sitesPath()) as $site) {
                $hosts[] = $this->domainHost($site, $domain);
            }
        }

        if ($this->files->isDir($this->nginxPath())) {
            foreach ($this->files->scandir($this->nginxPath()) as $site) {
                if (str_ends_with($site, '.' . $domain)) {
                    $hosts[] = $site;
                }
            }
        }

        return collect($hosts)
            ->map(fn (string $host) => strtolower($host))
            ->filter(fn (string $host) => $this->validHostname($host))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function domainHost(string $site, string $domain): string
    {
        return str_ends_with($site, '.' . $domain) ? $site : $site . '.' . $domain;
    }

    private function validHostname(string $host): bool
    {
        return (bool)preg_match('/\A[a-z0-9][a-z0-9._-]*[a-z0-9]\z/i', $host);
    }

    /**
     * Write Valet's managed block into /etc/hosts.
     *
     * @param array<int, string> $hosts
     */
    private function writeHostsBlock(array $hosts): void
    {
        $contents = $this->files->exists($this->hostsPath) ? $this->files->get($this->hostsPath) : '';
        $contents = rtrim($this->withoutValetHosts($contents));

        $block = [
            self::START_MARKER,
            '# Managed by Valet Cubed. Do not edit between these markers.',
        ];

        foreach ($hosts as $host) {
            $block[] = '127.0.0.1' . "\t" . $host;
        }

        $block[] = self::END_MARKER;

        $contents = trim($contents) === ''
            ? implode(PHP_EOL, $block) . PHP_EOL
            : $contents . PHP_EOL . PHP_EOL . implode(PHP_EOL, $block) . PHP_EOL;

        $this->writeHostsFile($contents);
    }

    private function removeValetHosts(): void
    {
        if (!$this->files->exists($this->hostsPath)) {
            return;
        }

        $contents = $this->files->get($this->hostsPath);
        if (!str_contains($contents, self::START_MARKER) && !str_contains($contents, self::FORMER_START_MARKER)) {
            return;
        }
        $this->writeHostsFile(rtrim($this->withoutValetHosts($contents)) . PHP_EOL);
    }

    private function writeHostsFile(string $contents): void
    {
        $temporaryPath = VALET_HOME_PATH . '/hosts.tmp';

        $this->files->putAsUser($temporaryPath, $contents);
        $this->cli->passthru(sprintf(
            'cp %s %s',
            escapeshellarg($temporaryPath),
            escapeshellarg($this->hostsPath)
        ), RunAs::Root);
        $this->files->unlink($temporaryPath);
    }

    private function withoutValetHosts(string $contents): string
    {
        foreach ([[self::START_MARKER, self::END_MARKER], [self::FORMER_START_MARKER, self::FORMER_END_MARKER]] as [$start, $end]) {
            $pattern = sprintf('/\n?%s\n.*?\n%s\n?/s', preg_quote($start, '/'), preg_quote($end, '/'));
            $contents = preg_replace($pattern, PHP_EOL, $contents) ?? $contents;
        }

        return $contents;
    }
}
