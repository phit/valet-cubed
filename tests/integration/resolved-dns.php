<?php

// This test changes resolver configuration and must only run in a disposable VM.
if (getenv('CI') !== 'true' || getenv('VALET_DISPOSABLE_DNS_TEST') !== '1' || posix_geteuid() !== 0) {
    throw new RuntimeException('This test requires a disposable CI VM and root.');
}

require __DIR__ . '/../../vendor/autoload.php';

use Valet\CommandLine;
use Valet\Filesystem;
use Valet\ResolvedDns;

if (file_exists(ResolvedDns::UNIT) || file_exists(ResolvedDns::DELEGATE)) {
    throw new RuntimeException('Refusing to replace existing resolver files.');
}

$dns = new ResolvedDns(new Filesystem(), new CommandLine());

// Global and per-link servers only; newer resolvectl also lists dns-delegate
// entries, which include Valet's own delegation.
$upstreamServers = function (): string {
    $lines = explode("\n", (string) shell_exec('resolvectl dns'));

    return implode("\n", preg_grep('/^(Global|Link \d+)/', $lines));
};
$upstreamBefore = $upstreamServers();

try {
    if (!$dns->activate('valet-ci.test')) {
        throw new RuntimeException('Failed to activate the delegated resolver.');
    }

    $response = shell_exec('getent ahostsv4 tenant.app.valet-ci.test');
    if (!is_string($response) || strpos($response, '127.0.0.1') === false) {
        throw new RuntimeException('Wildcard hostname did not resolve through NSS.');
    }

    if (!$dns->activate('valet-next.test')) {
        throw new RuntimeException('Failed to change the delegated domain.');
    }
    $response = shell_exec('getent ahostsv4 tenant.app.valet-next.test');
    if (!is_string($response) || strpos($response, '127.0.0.1') === false) {
        throw new RuntimeException('The new wildcard domain did not resolve.');
    }

    $upstreamAfter = $upstreamServers();
    if ($upstreamBefore !== $upstreamAfter) {
        throw new RuntimeException("Existing system DNS server settings changed.\nBefore:\n{$upstreamBefore}\nAfter:\n{$upstreamAfter}");
    }
} finally {
    $dns->deactivate();
}

if (file_exists(ResolvedDns::UNIT) || file_exists(ResolvedDns::DELEGATE)) {
    throw new RuntimeException('Valet resolver files remain after cleanup.');
}

echo "Systemd DNS delegation integration test passed.\n";
