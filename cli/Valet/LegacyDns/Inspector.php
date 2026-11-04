<?php

namespace Valet\LegacyDns;

use Valet\ResolvedDns;

/**
 * Collects, without changing anything, the system state that legacy
 * Valet DNS integrations could have touched.
 */
class Inspector
{
    public const RESOLV = '/etc/resolv.conf';
    public const RESOLV_BACKUP = '/etc/resolv.conf.bak';
    public const DNSMASQ_CONF = '/etc/dnsmasq.conf';
    public const DNSMASQ_CONF_BACKUP = '/etc/dnsmasq.conf.bak';
    public const DNSMASQ_DIR = '/etc/dnsmasq.d';
    public const DNSMASQ_OPTIONS = '/etc/dnsmasq.d/options';
    public const DNSMASQ_VALET = '/etc/dnsmasq.d/valet';
    public const DNSMASQ_NM = '/etc/dnsmasq.d/network-manager';
    public const DNSMASQ_DEFAULT = '/etc/default/dnsmasq';
    public const NM_DIR = '/etc/NetworkManager/conf.d';
    public const NM_VALET = '/etc/NetworkManager/conf.d/valet.conf';
    public const NM_MAIN = '/etc/NetworkManager/NetworkManager.conf';
    public const WATCHER_DIR = '/opt/valet-linux';
    public const WATCHER_UNIT = '/etc/systemd/system/valet-dns.service';
    public const WATCHER_SYSV = '/etc/init.d/valet-dns';
    public const RESOLVED_CONF = '/etc/systemd/resolved.conf';
    public const RESOLVED_CONF_BACKUP = '/etc/systemd/resolved.conf.bak';
    public const RC_LOCAL_BACKUP = '/etc/rc.local.bak';
    public const HOSTS = '/etc/hosts';

    public const UNITS = [
        'systemd-resolved.service',
        'dnsmasq.service',
        'NetworkManager.service',
        'valet-dns.service',
        ResolvedDns::SERVICE,
        ResolvedDns::FORMER_SERVICE,
    ];

    private const PATHS = [
        self::RESOLV, self::RESOLV_BACKUP, self::DNSMASQ_CONF, self::DNSMASQ_CONF_BACKUP, self::DNSMASQ_DIR,
        self::DNSMASQ_OPTIONS, self::DNSMASQ_VALET, self::DNSMASQ_NM, self::DNSMASQ_DEFAULT, self::NM_VALET,
        self::NM_MAIN, self::WATCHER_DIR, self::WATCHER_UNIT, self::WATCHER_SYSV, self::RESOLVED_CONF,
        self::RESOLVED_CONF_BACKUP, self::RC_LOCAL_BACKUP, self::HOSTS, ResolvedDns::UNIT, ResolvedDns::DELEGATE,
        ResolvedDns::FORMER_UNIT, ResolvedDns::FORMER_DELEGATE,
    ];

    private const PACKAGE_PATHS = [self::DNSMASQ_CONF, self::DNSMASQ_DEFAULT, self::DNSMASQ_NM];

    public function __construct(private System $system)
    {
    }

    /**
     * @return array{nodes: array<string, mixed>, dirs: array<string, list<string>>, units: array<string, mixed>,
     *     packages: array<string, mixed>, systemd: int, domain: ?string}
     */
    public function inspect(?string $domain = null): array
    {
        $nodes = [];
        foreach (self::PATHS as $path) {
            $nodes[$path] = $this->system->node($path);
        }

        $dirs = [];
        foreach ([self::DNSMASQ_DIR, self::NM_DIR, self::WATCHER_DIR] as $dir) {
            $dirs[$dir] = $this->system->listDir($dir);
            foreach ($dirs[$dir] as $entry) {
                $nodes[$dir . '/' . $entry] ??= $this->system->node($dir . '/' . $entry);
            }
        }

        $systemd = $this->system->systemdVersion();
        $units = [];
        foreach (self::UNITS as $unit) {
            $units[$unit] = $systemd > 0
                ? $this->system->unit($unit)
                : ['load' => 'not-found', 'active' => 'inactive', 'enabled' => '', 'preset' => ''];
        }

        $packages = [];
        foreach (self::PACKAGE_PATHS as $path) {
            $packages[$path] = $this->system->packageFile($path);
        }

        return [
            'nodes' => $nodes,
            'dirs' => $dirs,
            'units' => $units,
            'packages' => $packages,
            'systemd' => $systemd,
            'domain' => $domain,
        ];
    }
}
