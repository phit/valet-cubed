<?php

namespace Valet\LegacyDns;

/**
 * Exact contents written by released Valet Linux+ versions.
 *
 * The hashes were taken from cli/stubs in every upstream tag from 1.0 to
 * 2.0.3 and commit 42d1a94. Only files matching these hashes are treated as
 * Valet-owned; anything else is preserved.
 */
final class Fingerprints
{
    /**
     * /etc/dnsmasq.conf replacement (all releases).
     */
    public const DNSMASQ_CONF = [
        'd38a7a77a9fc20fb7164e1bba4edfbcbae3fe22f03005bd823ffb8c8d27826ea',
    ];

    /**
     * /etc/dnsmasq.d/options for releases using the valet-dns watcher.
     */
    public const OPTIONS_WATCHER = [
        '720ce1c363097dc72d6f8e2689a22716d1f37eea4751c3985780d0136756cb18', // 1.0 - 1.5.1
        'ddc479b80608dcafa6b154f1b69a3a5b4d88f99e87b9fd52d5892c981e9f902c', // 1.5.3 - 1.6.0
    ];

    /**
     * /etc/dnsmasq.d/options for releases listening on every address.
     */
    public const OPTIONS_WILDCARD = [
        '4b745fe5fd92a9adb87da82b189ae2fbb2a2be59a69daeb4258b223f533cc7e7', // 1.6.1 - 2.0.3
    ];

    /**
     * /etc/NetworkManager/conf.d/valet.conf ("[main]\ndns=none\n").
     */
    public const NETWORK_MANAGER = [
        'c8b08997881d43c628887ffae30227e1316afd022cc8642a0321e85e498b6886',
    ];

    /**
     * /opt/valet-linux/valet-dns watcher script.
     */
    public const WATCHER_SCRIPT = [
        'ff99294c1b35b171a89d48ff1b0f36bfcf246473252647d45c6b736ca9cbf8d4', // 1.0 - 1.5.1
        '30eded37f06972a0894f349e147fe0128f60f0da73349e13125101693e5d6d74', // 1.5.3 - 1.5.5
        '546a3d2feba9ed875f2a03c46080fc6be946626314722d5292962f7ddac8d2ac', // 1.5.6 - 1.5.8
        '06bd3a75e5131b4717275e362c79eda30289cba043caf8ebca8adf08d14fcba0', // 1.6.0
    ];

    /**
     * /etc/systemd/system/valet-dns.service.
     */
    public const WATCHER_SYSTEMD_UNIT = [
        '88020928e9cbf1fa9550c43b51d3ff1c586752e63b0aaaf6b768b3d1da7a7ec9',
    ];

    /**
     * /etc/init.d/valet-dns.
     */
    public const WATCHER_SYSV_SCRIPT = [
        'aa64d788f8f726966c582a7bbc7f63e2a22dcd8a175fd476d71de080d8dc448a',
    ];

    /**
     * Files the watcher creates in /opt/valet-linux besides its script.
     */
    public const WATCHER_RUNTIME_FILES = [
        'dns-servers', 'resolv.conf', 'watch.log', 'watch.pid', 'resolvfiles.log',
    ];

    /**
     * The public resolvers hard-coded by 1.6.1 and later.
     */
    public const PUBLIC_UPSTREAMS = ['1.1.1.1', '8.8.8.8'];

    public static function matches(?string $content, array $hashes): bool
    {
        return $content !== null && in_array(hash('sha256', $content), $hashes, true);
    }

    /**
     * Parse /etc/dnsmasq.d/valet. Returns the domain and whether the public
     * upstreams were included, or null if the file was modified.
     *
     * @return array{domain: string, publicUpstreams: bool}|null
     */
    public static function valetConfig(?string $content): ?array
    {
        if ($content === null || !preg_match(
            '/\Aaddress=\/\.([A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?)\/127\.0\.0\.1\n(server=1\.1\.1\.1\nserver=8\.8\.8\.8\n)?\z/',
            $content,
            $matches
        )) {
            return null;
        }

        return ['domain' => $matches[1], 'publicUpstreams' => isset($matches[2]) && $matches[2] !== ''];
    }
}
