<?php

namespace Valet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Valet\LegacyDns\Inspector;
use Valet\LegacyDns\Journal;
use Valet\LegacyDns\Migrator;
use Valet\LegacyDns\Plan;
use Valet\LegacyDns\Planner;
use Valet\LegacyDns\System;
use Valet\LegacyDns\Verifier;

/**
 * Runs the migration against a scratch root with a simulated systemd,
 * package database and resolver.
 */
class LegacyDnsMigrationTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../files/legacy-dns/';
    private const DEBIAN_DEFAULT = "# Defaults for dnsmasq\n#IGNORE_RESOLVCONF=yes\nENABLED=1\n";
    private const DEBIAN_CONF = "# Configuration file for dnsmasq.\n#conf-dir=/etc/dnsmasq.d\n";
    private const NM_SNIPPET = "bind-interfaces\nexcept-interface=lo\n";

    private string $root;

    /**
     * @var array<string, array{load: string, active: string, enabled: string, preset: string}>
     */
    private array $units = [];

    /**
     * @var array<string, array{package: string, algorithm: string, digest: string}>
     */
    private array $packages = [];

    /**
     * @var array<string, bool>
     */
    private array $immutable = [];

    /**
     * @var list<string>
     */
    private array $commands = [];

    /**
     * @var array<string, int>
     */
    private array $failures = [];

    private bool $resolvedBroken = false;

    /**
     * Whether systemd-resolved holds 127.0.0.53:53. It cannot when it starts
     * while a legacy dnsmasq listens on every address, and such a dnsmasq
     * cannot start while resolved holds it.
     */
    private bool $resolvedStub = true;
    private bool $backendCalled = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/valet-legacy-dns-' . bin2hex(random_bytes(6));
        foreach (['/etc/dnsmasq.d', '/etc/default', '/etc/NetworkManager/conf.d', '/etc/systemd/system',
            '/etc/systemd/dns-delegate.d', '/etc/init.d', '/opt', '/var/lib', '/run/systemd/resolve'] as $dir) {
            mkdir($this->root . $dir, 0755, true);
        }
        $this->put('/etc/hosts', "127.0.0.1\tlocalhost\n");
        $this->units = [
            'systemd-resolved.service' => ['load' => 'loaded', 'active' => 'active', 'enabled' => 'enabled', 'preset' => 'enabled'],
            'NetworkManager.service' => ['load' => 'loaded', 'active' => 'active', 'enabled' => 'enabled', 'preset' => 'enabled'],
            'dnsmasq.service' => ['load' => 'not-found', 'active' => 'inactive', 'enabled' => '', 'preset' => ''],
            'valet-dns.service' => ['load' => 'not-found', 'active' => 'inactive', 'enabled' => '', 'preset' => ''],
            'valet-linux-plus-dns.service' => ['load' => 'not-found', 'active' => 'inactive', 'enabled' => '', 'preset' => ''],
        ];
        $this->link('/etc/resolv.conf', '../run/systemd/resolve/stub-resolv.conf');
        $this->put('/run/systemd/resolve/stub-resolv.conf', "nameserver 127.0.0.53\n");
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+rwX ' . escapeshellarg($this->root) . ' && rm -rf ' . escapeshellarg($this->root));
    }

    public function testFreshSystemHasNoLegacyStateAndNoSteps(): void
    {
        $plan = $this->plan();

        $this->assertFalse($plan->legacy());
        $this->assertSame([], $plan->steps);
        $this->assertFalse($plan->blocked());
    }

    public function testDetectsTwoPointXInstallAndPlansARestoreOfResolved(): void
    {
        $this->installTwoPointX();
        $plan = $this->plan();

        $this->assertStringContainsString('1.6.1 - 2.0.3', $plan->generation);
        $this->assertSame('restore', $plan->decisions['resolved']['chosen']);
        $this->assertStringContainsString('stub-resolv.conf', $plan->decisions['resolved']['evidence']);
        $this->assertSame('disable', $plan->decisions['dnsmasq']['chosen']);
        $this->assertFalse($plan->blocked());
        $this->assertSame('systemd-resolved', $plan->resolver);

        $summaries = array_map([Plan::class, 'stepSummary'], $plan->steps);
        $this->assertStringStartsWith('Remove /etc/dnsmasq.d/valet', $summaries[0]);
        $this->assertContains('/etc/dnsmasq.d/options', array_column($plan->steps, 'path'));
        $this->assertContains('/etc/NetworkManager/conf.d/valet.conf', array_column($plan->steps, 'path'));
        $this->assertContains('/etc/default/dnsmasq', array_column($plan->steps, 'path'));
        $this->assertContains('/etc/dnsmasq.d/network-manager', array_column($plan->steps, 'path'));
        $this->assertSame('/opt/valet-linux', end($plan->steps)['op'] === 'valet-backend'
            ? $plan->steps[count($plan->steps) - 2]['path'] : null);

        $units = array_values(array_filter($plan->steps, fn ($step) => $step['op'] === 'unit'));
        $this->assertSame(['dnsmasq.service', 'systemd-resolved.service', 'NetworkManager.service'], array_column($units, 'unit'));
        $this->assertSame('valet-backend', end($plan->steps)['op']);
    }

    public function testDryRunPlanningDoesNotChangeTheSystem(): void
    {
        $this->installTwoPointX();
        $before = $this->tree();
        $this->plan();

        $this->assertSame($before, $this->tree());
        $this->assertSame([], array_filter($this->commands, fn ($command) => preg_match('/^systemctl (enable|disable|start|stop|restart)/', $command)));
    }

    public function testMigratesTwoPointXAndRollsBackToTheExactPreviousState(): void
    {
        $this->installTwoPointX();
        $before = $this->tree();
        $unitsBefore = $this->units;

        $result = $this->migrator()->apply($this->plan(), ['example.com']);

        $this->assertSame('completed', $result['status'], implode("\n", $result['log']));
        $this->assertTrue($this->backendCalled);
        $this->assertFileDoesNotExist($this->root . '/etc/dnsmasq.d/valet');
        $this->assertFileDoesNotExist($this->root . '/etc/dnsmasq.d/options');
        $this->assertFileDoesNotExist($this->root . '/etc/NetworkManager/conf.d/valet.conf');
        $this->assertFileDoesNotExist($this->root . '/opt/valet-linux');
        $this->assertSame(self::DEBIAN_CONF, $this->get('/etc/dnsmasq.conf'));
        $this->assertFileDoesNotExist($this->root . '/etc/dnsmasq.conf.bak');
        $this->assertSame(self::DEBIAN_DEFAULT, $this->get('/etc/default/dnsmasq'));
        $this->assertSame(self::NM_SNIPPET, $this->get('/etc/dnsmasq.d/network-manager'));
        $this->assertSame('active', $this->units['systemd-resolved.service']['active']);
        $this->assertSame('enabled', $this->units['systemd-resolved.service']['enabled']);
        $this->assertSame('inactive', $this->units['dnsmasq.service']['active']);
        $this->assertSame('disabled', $this->units['dnsmasq.service']['enabled']);
        $this->assertStringContainsString("127.0.0.1\tapp.test", $this->get('/etc/hosts'));

        $this->assertSame([], $this->plan()->steps, 'A second run must find nothing left to migrate.');

        $rollback = $this->migrator()->rollback($result['id']);
        $this->assertSame('rolled-back', $rollback['status'], implode("\n", $rollback['log']));
        $this->assertSame($before, $this->tree());
        foreach ($unitsBefore as $unit => $state) {
            $this->assertSame([$state['active'], $state['enabled']], [$this->units[$unit]['active'], $this->units[$unit]['enabled']], $unit);
        }
    }

    public function testMigratesTheValetDnsWatcherLayout(): void
    {
        $this->installWatcher();
        $before = $this->tree();
        $plan = $this->plan();

        $this->assertStringContainsString('1.0 - 1.6.0', $plan->generation);
        $this->assertSame(['op' => 'unit', 'unit' => 'valet-dns.service'], array_intersect_key($plan->steps[0], ['op' => 1, 'unit' => 1]));
        $this->assertSame('restore', $plan->decisions['resolved']['chosen'], 'The backup records the stub symlink.');

        $result = $this->migrator()->apply($plan, ['example.com']);
        $this->assertSame('completed', $result['status'], implode("\n", $result['log']));
        $this->assertSame('../run/systemd/resolve/stub-resolv.conf', readlink($this->root . '/etc/resolv.conf'));
        $this->assertFileDoesNotExist($this->root . '/etc/resolv.conf.bak');
        $this->assertFileDoesNotExist($this->root . '/etc/systemd/system/valet-dns.service');
        $this->assertFileDoesNotExist($this->root . '/opt/valet-linux');
        $this->assertContains('systemctl daemon-reload', $this->commands);

        $this->assertSame('rolled-back', $this->migrator()->rollback()['status']);
        $this->assertSame($before, $this->tree());
    }

    public function testMixedUpgradeWithDanglingResolvConfRestoresTheBackup(): void
    {
        $this->installTwoPointX();
        unlink($this->root . '/etc/resolv.conf');
        $this->link('/etc/resolv.conf', '/opt/valet-linux/resolv.conf');
        $this->link('/etc/resolv.conf.bak', '../run/systemd/resolve/stub-resolv.conf');

        $plan = $this->plan();
        $this->assertStringStartsWith('mixed', $plan->generation);
        $this->assertStringContainsString('(dangling)', implode("\n", $plan->evidence));
        $this->assertSame('restore', $plan->decisions['resolved']['chosen']);

        $this->assertSame('completed', $this->migrator()->apply($plan, ['example.com'])['status']);
        $this->assertSame('../run/systemd/resolve/stub-resolv.conf', readlink($this->root . '/etc/resolv.conf'));
    }

    public function testWatcherLayoutWithoutBackupIsBlocked(): void
    {
        $this->installWatcher();
        unlink($this->root . '/etc/resolv.conf.bak');

        $plan = $this->plan();
        $this->assertTrue($plan->blocked());
        $this->assertStringContainsString('no usable /etc/resolv.conf.bak', implode("\n", $plan->conflicts));
        $this->assertNotContains('valet-backend', array_column($plan->steps, 'op'));

        $this->expectException(RuntimeException::class);
        $this->migrator()->apply($plan, []);
    }

    public function testAmbiguousResolverRequiresAnExplicitDecision(): void
    {
        $this->installTwoPointX();
        unlink($this->root . '/etc/resolv.conf');
        $this->put('/etc/resolv.conf', "# Generated by NetworkManager\nnameserver 192.168.1.1\n");

        $plan = $this->plan();
        $this->assertNull($plan->decisions['resolved']['chosen']);
        $this->assertTrue($plan->blocked());
        $this->assertSame(['resolved'], $plan->unresolvedDecisions());

        $plan = $this->plan(['resolved' => 'leave']);
        $this->assertFalse($plan->blocked());
        $this->assertNotContains('systemd-resolved.service', array_column($plan->steps, 'unit'));
    }

    public function testNeverEnablesResolvedWithoutEvidence(): void
    {
        $this->installTwoPointX();
        unlink($this->root . '/etc/resolv.conf');
        $this->put('/etc/resolv.conf', "nameserver 192.168.1.1\n");
        $this->units['systemd-resolved.service']['preset'] = 'disabled';

        $plan = $this->plan();
        $this->assertSame('leave', $plan->decisions['resolved']['chosen']);
        $this->assertNotContains('systemd-resolved.service', array_column($plan->steps, 'unit'));
    }

    public function testPreservesModifiedFilesAndKeepsDnsmasqWithUserSettings(): void
    {
        $this->installTwoPointX();
        $this->put('/etc/dnsmasq.d/valet', "address=/.test/127.0.0.1\nserver=/corp.example/10.0.0.1\n");
        $this->put('/etc/dnsmasq.d/lan.conf', "bind-interfaces\nlisten-address=192.168.50.1\ndhcp-range=192.168.50.10,192.168.50.99\n");

        $plan = $this->plan();
        $this->assertNotContains('/etc/dnsmasq.d/valet', array_column($plan->steps, 'path'));
        $this->assertStringContainsString('/etc/dnsmasq.d/valet contains local changes', implode("\n", $plan->warnings));
        $this->assertSame('keep', $plan->decisions['dnsmasq']['chosen']);
        $this->assertFalse($plan->blocked(), implode("\n", $plan->conflicts));

        $this->assertSame('completed', $this->migrator()->apply($plan, ['example.com'])['status']);
        $this->assertFileExists($this->root . '/etc/dnsmasq.d/valet');
        $this->assertFileExists($this->root . '/etc/dnsmasq.d/lan.conf');
        $this->assertSame('active', $this->units['dnsmasq.service']['active']);
    }

    public function testKeepingAWildcardDnsmasqNextToResolvedIsAConflict(): void
    {
        $this->installTwoPointX();
        $this->put('/etc/dnsmasq.d/cache.conf', "cache-size=1000\n");

        $plan = $this->plan();
        $this->assertSame('keep', $plan->decisions['dnsmasq']['chosen']);
        $this->assertTrue($plan->blocked());
        $this->assertStringContainsString('127.0.0.53:53', implode("\n", $plan->conflicts));

        $this->assertFalse($this->plan(['dnsmasq' => 'disable'])->blocked());
    }

    public function testCustomisedDnsmasqBackupIsRestoredWithAWarning(): void
    {
        $this->installTwoPointX();
        $this->put('/etc/dnsmasq.conf.bak', "# my settings\nlog-queries\n");

        $plan = $this->plan();
        $this->assertStringContainsString('differs from the packaged default', implode("\n", $plan->warnings));
        $this->assertSame('keep', $plan->decisions['dnsmasq']['chosen'], 'log-queries is a user setting');
    }

    public function testFailingServiceRollsEverythingBack(): void
    {
        $this->installTwoPointX();
        $before = $this->tree();
        $this->failures["systemctl start 'systemd-resolved.service'"] = 1;

        $result = $this->migrator()->apply($this->plan(), ['example.com']);

        $this->assertSame('rolled-back', $result['status'], implode("\n", $result['log']));
        $this->assertSame($before, $this->tree());
        $this->assertSame('active', $this->units['dnsmasq.service']['active']);
        $this->assertSame('enabled', $this->units['dnsmasq.service']['enabled']);
        $this->assertSame('inactive', $this->units['systemd-resolved.service']['active']);
        $this->assertSame('disabled', $this->units['systemd-resolved.service']['enabled']);
        $this->assertFalse($this->backendCalled);
    }

    public function testLookupRegressionRollsBack(): void
    {
        $this->installTwoPointX();
        $before = $this->tree();
        $this->resolvedBroken = true;

        $result = $this->migrator()->apply($this->plan(), ['example.com']);

        $this->assertSame('rolled-back', $result['status']);
        $this->assertStringContainsString('example.com resolved before', implode("\n", $result['log']));
        $this->assertFalse($this->backendCalled, 'Valet must not be reconfigured before normal DNS is verified.');
        $this->assertSame($before, $this->tree());
        $this->assertSame([0, "93.184.215.14   STREAM example.com\n"], $this->resolve('example.com'));
    }

    public function testNamesThatAlreadyFailedDoNotBlockMigration(): void
    {
        $this->installTwoPointX();
        $result = $this->migrator()->apply($this->plan(), ['example.com', 'unresolvable.invalid']);

        $this->assertSame('completed', $result['status']);
        $this->assertStringContainsString('unresolvable.invalid (unresolved)', implode("\n", $result['log']));
    }

    public function testOptionalPackageRestoreFailureIsReportedNotFatal(): void
    {
        $this->installTwoPointX();
        $this->failures['apt-get download'] = 100;

        $result = $this->migrator()->apply($this->plan(), ['example.com']);

        $this->assertSame('completed', $result['status']);
        $this->assertFileDoesNotExist($this->root . '/etc/dnsmasq.d/network-manager');
        $journal = $this->journal()->load((string) $result['id']);
        $this->assertStringContainsString('was skipped', implode("\n", $journal['warnings']));
    }

    public function testUnfinishedMigrationMustBeRolledBackBeforeAnotherRun(): void
    {
        $this->installTwoPointX();
        $before = $this->tree();
        $result = $this->migrator()->apply($this->plan(), ['example.com']);

        // Simulate a process killed while the last step was running.
        $journal = $this->journal()->load((string) $result['id']);
        $journal['status'] = 'applying';
        $journal['steps'][array_key_last($journal['steps'])]['state'] = 'applying';
        $this->journal()->save($journal);

        try {
            $this->migrator()->apply($this->plan(), ['example.com']);
            $this->fail('A second migration must not start.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dns:rollback', $exception->getMessage());
        }

        $this->assertSame('rolled-back', $this->migrator()->rollback()['status']);
        $this->assertSame($before, $this->tree());
    }

    public function testImmutableBackupIsUnlockedRestoredAndLockedAgain(): void
    {
        $this->installWatcher();
        unlink($this->root . '/etc/resolv.conf.bak');
        $this->put('/etc/resolv.conf.bak', "nameserver 192.168.1.1\n");
        $this->immutable['/etc/resolv.conf.bak'] = true;
        $before = $this->tree();

        $plan = $this->plan(['resolved' => 'leave']);
        $this->assertSame(['op' => 'immutable', 'path' => '/etc/resolv.conf.bak', 'value' => false], array_intersect_key($plan->steps[0], ['op' => 1, 'path' => 1, 'value' => 1]));
        $relock = array_values(array_filter($plan->steps, fn ($step) => $step['op'] === 'immutable' && $step['value'] === true));
        $this->assertSame('/etc/resolv.conf', $relock[0]['path']);

        $this->assertSame('completed', $this->migrator()->apply($plan, ['example.com'])['status']);
        $this->assertSame("nameserver 192.168.1.1\n", $this->get('/etc/resolv.conf'));
        $this->assertTrue($this->immutable['/etc/resolv.conf']);

        $this->assertSame('rolled-back', $this->migrator()->rollback()['status']);
        $this->assertSame($before, $this->tree());
        $this->assertTrue($this->immutable['/etc/resolv.conf.bak']);
    }

    public function testJournalWithLoosePermissionsIsRejected(): void
    {
        $this->installTwoPointX();
        $result = $this->migrator()->apply($this->plan(), ['example.com']);
        chmod($this->root . Journal::DIR . '/' . $result['id'] . '.json', 0644);

        $this->expectExceptionMessageMatches('/Refusing to use/');
        $this->migrator()->rollback($result['id']);
    }

    public function testResidueFromLegacyUninstallIsOnlyReported(): void
    {
        unlink($this->root . '/etc/resolv.conf');
        $this->link('/etc/resolv.conf', '/run/systemd/resolve/resolv.conf');
        $this->units['systemd-resolved.service'] = ['load' => 'loaded', 'active' => 'active', 'enabled' => 'disabled', 'preset' => 'enabled'];

        $plan = $this->plan();
        $this->assertFalse($plan->legacy());
        $this->assertSame([], $plan->steps);
        $this->assertStringContainsString('legacy "valet uninstall"', implode("\n", $plan->manual));
    }

    public function testUnmodifiedWatcherDirectoryWithCustomNameserversIsKept(): void
    {
        $this->installWatcher();
        $this->put('/opt/valet-linux/custom-nameservers', "nameserver 10.1.1.1\n");

        $plan = $this->plan();
        $this->assertNotContains('/opt/valet-linux', array_column($plan->steps, 'path'));
        $this->assertStringContainsString('custom-nameservers', implode("\n", $plan->manual));
    }

    public function testSocketActivatedResolverGetsItsEnablementRestored(): void
    {
        // Seen on Fedora 44: socket activation restarted resolved after the
        // legacy installer disabled it, and the legacy dnsmasq then failed.
        $this->installTwoPointX();
        $this->units['systemd-resolved.service'] = ['load' => 'loaded', 'active' => 'active', 'enabled' => 'disabled', 'preset' => 'enabled'];
        $this->units['dnsmasq.service'] = ['load' => 'loaded', 'active' => 'failed', 'enabled' => 'enabled', 'preset' => 'disabled'];

        $plan = $this->plan();
        $this->assertSame('restore', $plan->decisions['resolved']['chosen']);
        $resolved = array_values(array_filter($plan->steps, fn ($step) => ($step['unit'] ?? null) === 'systemd-resolved.service'));
        $this->assertSame([true, 'restart'], [$resolved[0]['enable'], $resolved[0]['action']], 'restart to regain the stub listener');

        $this->assertSame('completed', $this->migrator()->apply($plan, ['example.com'])['status']);
        $this->assertSame('enabled', $this->units['systemd-resolved.service']['enabled']);
        $this->assertSame('disabled', $this->units['dnsmasq.service']['enabled']);
    }

    public function testActiveResolverIsRestartedOnceTheLegacyDnsmasqReleasesPort53(): void
    {
        $this->installTwoPointX();
        $this->units['systemd-resolved.service'] = ['load' => 'loaded', 'active' => 'active', 'enabled' => 'enabled', 'preset' => 'enabled'];

        $plan = $this->plan();
        $units = array_values(array_filter($plan->steps, fn ($step) => $step['op'] === 'unit'));
        $this->assertSame(['dnsmasq.service', 'systemd-resolved.service'], array_slice(array_column($units, 'unit'), 0, 2));
        $this->assertSame('restart', $units[1]['action']);
    }

    public function testMissingStubListenerRollsTheMigrationBack(): void
    {
        // Seen on Fedora 44: NSS lookups worked, but nothing answered on
        // 127.0.0.53, so programs reading resolv.conf had no DNS.
        $this->installTwoPointX();
        $before = $this->tree();
        mkdir($this->root . '/proc/net', 0755, true);
        $header = "  sl  local_address rem_address   st\n";
        file_put_contents($this->root . '/proc/net/udp', $header . "   0: 3600007F:0035 00000000:0000 07\n");
        file_put_contents($this->root . '/proc/net/tcp', $header . "   0: 3600007F:0035 00000000:0000 0A\n");

        $result = $this->migrator()->apply($this->plan(), ['example.com']);

        $this->assertSame('rolled-back', $result['status']);
        $this->assertStringContainsString('not listening on 127.0.0.53:53', implode("\n", $result['log']));
        $before['/proc'] = $this->tree()['/proc'];
        $before['/proc/net'] = $this->tree()['/proc/net'];
        $before['/proc/net/tcp'] = $this->tree()['/proc/net/tcp'];
        $before['/proc/net/udp'] = $this->tree()['/proc/net/udp'];
        ksort($before);
        $this->assertSame($before, $this->tree());
    }

    public function testRollbackRestoresCompetingServicesInTheirOriginalOrder(): void
    {
        // Seen on Fedora 44: undoing steps in reverse restarted resolved first,
        // which took 127.0.0.53:53, so the legacy dnsmasq could not start again.
        $this->installTwoPointX();
        $this->units['systemd-resolved.service'] = ['load' => 'loaded', 'active' => 'active', 'enabled' => 'disabled', 'preset' => 'enabled'];
        $this->resolvedStub = false;
        $before = $this->tree();

        $result = $this->migrator()->apply($this->plan(), ['example.com']);
        $this->assertSame('completed', $result['status'], implode("\n", $result['log']));
        $this->assertTrue($this->resolvedStub, 'migration must give resolved its stub listener back');

        $rollback = $this->migrator()->rollback();
        $this->assertSame('rolled-back', $rollback['status'], implode("\n", $rollback['log']));
        $this->assertSame($before, $this->tree());
        $this->assertSame(['active', 'enabled'], [$this->units['dnsmasq.service']['active'], $this->units['dnsmasq.service']['enabled']]);
        $this->assertSame(['active', 'disabled'], [$this->units['systemd-resolved.service']['active'], $this->units['systemd-resolved.service']['enabled']]);
        $this->assertFalse($this->resolvedStub, 'the pre-migration state had dnsmasq on port 53');
    }

    public function testPackagedDnsmasqDefaultsAreNotUserSettings(): void
    {
        // Fedora's stock dnsmasq.conf has active lines (user, group, local-service).
        $fedora = "user=dnsmasq\ngroup=dnsmasq\nlocal-service=host\nconf-dir=/etc/dnsmasq.d,.rpmnew,.rpmsave,.rpmorig\n";
        $this->installTwoPointX();
        $this->put('/etc/dnsmasq.conf.bak', $fedora);
        $this->packages['/etc/dnsmasq.conf'] = ['package' => 'dnsmasq', 'algorithm' => 'md5', 'digest' => md5($fedora)];

        $plan = $this->plan();
        $this->assertSame('disable', $plan->decisions['dnsmasq']['chosen'], implode("\n", $plan->evidence));

        $this->put('/etc/dnsmasq.conf.bak', $fedora . "address=/router.home/192.168.1.1\n");
        $this->assertSame('keep', $this->plan()->decisions['dnsmasq']['chosen']);
    }

    public function testFingerprintsMatchTheFixtures(): void
    {
        $this->assertTrue(\Valet\LegacyDns\Fingerprints::matches((string) file_get_contents(self::FIXTURES . 'valet-dns'), \Valet\LegacyDns\Fingerprints::WATCHER_SCRIPT));
        $this->assertSame(['domain' => 'test', 'publicUpstreams' => true], \Valet\LegacyDns\Fingerprints::valetConfig("address=/.test/127.0.0.1\nserver=1.1.1.1\nserver=8.8.8.8\n"));
        $this->assertSame(['domain' => 'dev.local', 'publicUpstreams' => false], \Valet\LegacyDns\Fingerprints::valetConfig("address=/.dev.local/127.0.0.1\n"));
        $this->assertNull(\Valet\LegacyDns\Fingerprints::valetConfig("address=/.test/127.0.0.1\nserver=9.9.9.9\n"));
    }

    private function installTwoPointX(): void
    {
        $this->put('/etc/dnsmasq.conf', $this->fixture('dnsmasq.conf'));
        $this->put('/etc/dnsmasq.conf.bak', self::DEBIAN_CONF);
        $this->put('/etc/dnsmasq.d/options', $this->fixture('options-2.0.3'));
        $this->put('/etc/dnsmasq.d/valet', "address=/.test/127.0.0.1\nserver=1.1.1.1\nserver=8.8.8.8\n");
        $this->put('/etc/dnsmasq.d/README', "# files in this directory are read by dnsmasq\n");
        $this->put('/etc/default/dnsmasq', str_replace('#IGNORE', 'IGNORE', self::DEBIAN_DEFAULT));
        $this->put('/etc/NetworkManager/conf.d/valet.conf', $this->fixture('networkmanager.conf'));
        mkdir($this->root . '/opt/valet-linux');
        $this->units['systemd-resolved.service'] = ['load' => 'loaded', 'active' => 'inactive', 'enabled' => 'disabled', 'preset' => 'enabled'];
        $this->units['dnsmasq.service'] = ['load' => 'loaded', 'active' => 'active', 'enabled' => 'enabled', 'preset' => 'enabled'];
        $this->packages = [
            '/etc/dnsmasq.conf' => ['package' => 'dnsmasq-base', 'algorithm' => 'md5', 'digest' => md5(self::DEBIAN_CONF)],
            '/etc/default/dnsmasq' => ['package' => 'dnsmasq', 'algorithm' => 'md5', 'digest' => md5(self::DEBIAN_DEFAULT)],
            '/etc/dnsmasq.d/network-manager' => ['package' => 'network-manager', 'algorithm' => 'md5', 'digest' => md5(self::NM_SNIPPET)],
        ];
    }

    private function installWatcher(): void
    {
        $this->put('/etc/dnsmasq.conf', $this->fixture('dnsmasq.conf'));
        $this->put('/etc/dnsmasq.d/options', $this->fixture('options-1.5.8'));
        $this->put('/etc/dnsmasq.d/valet', "address=/.test/127.0.0.1\n");
        $this->put('/etc/NetworkManager/conf.d/valet.conf', $this->fixture('networkmanager.conf'));
        $this->put('/etc/systemd/system/valet-dns.service', $this->fixture('valet-dns.service'));
        mkdir($this->root . '/opt/valet-linux');
        $this->put('/opt/valet-linux/valet-dns', $this->fixture('valet-dns'));
        $this->put('/opt/valet-linux/resolv.conf', "nameserver 127.0.0.1\nnameserver 1.1.1.1\n");
        $this->put('/opt/valet-linux/dns-servers', "nameserver 192.168.1.1\n");
        $this->put('/opt/valet-linux/custom-nameservers', '');
        $this->put('/opt/valet-linux/watch.log', '');
        rename($this->root . '/etc/resolv.conf', $this->root . '/etc/resolv.conf.bak');
        $this->link('/etc/resolv.conf', '/opt/valet-linux/resolv.conf');
        $this->units['systemd-resolved.service'] = ['load' => 'loaded', 'active' => 'inactive', 'enabled' => 'disabled', 'preset' => 'enabled'];
        $this->units['dnsmasq.service'] = ['load' => 'loaded', 'active' => 'active', 'enabled' => 'enabled', 'preset' => 'enabled'];
        $this->units['valet-dns.service'] = ['load' => 'loaded', 'active' => 'active', 'enabled' => 'enabled', 'preset' => 'disabled'];
    }

    /**
     * @param array<string, string> $choices
     */
    private function plan(array $choices = []): Plan
    {
        return (new Planner())->plan((new Inspector($this->system()))->inspect('test'), $choices);
    }

    private function migrator(?callable $backend = null): Migrator
    {
        $system = $this->system();

        return new Migrator($system, $this->journal(), new Verifier($system, fn () => null, 2), function (string $domain) use ($backend) {
            $this->backendCalled = true;
            if ($backend !== null) {
                $backend();
            }
            $hosts = $this->get('/etc/hosts');
            $this->put('/etc/hosts', $hosts . "\n# Valet Linux+ hosts start\n# Managed by Valet Linux+. Do not edit between these markers.\n127.0.0.1\tapp.{$domain}\n# Valet Linux+ hosts end\n");
        });
    }

    private function journal(): Journal
    {
        return new Journal($this->system());
    }

    private function system(): System
    {
        return new System($this->root, fn (string $command) => $this->simulate($command));
    }

    /**
     * @return array{int, string}
     */
    private function simulate(string $command): array
    {
        $this->commands[] = $command;
        foreach ($this->failures as $prefix => $status) {
            if (str_starts_with($command, $prefix) || str_contains($command, $prefix)) {
                return [$status, 'simulated failure'];
            }
        }
        if ($command === 'systemctl --version 2>/dev/null') {
            return [0, "systemd 259 (259.9)\n"];
        }
        if (preg_match("/^systemctl show --no-pager '([^']+)'/", $command, $matches)) {
            $unit = $this->units[$matches[1]] ?? ['load' => 'not-found', 'active' => 'inactive', 'enabled' => '', 'preset' => ''];

            return [0, sprintf("LoadState=%s\nActiveState=%s\nUnitFileState=%s\nUnitFilePreset=%s\n", $unit['load'], $unit['active'], $unit['enabled'], $unit['preset'])];
        }
        if (preg_match("/^systemctl (enable|disable|start|stop|restart|disable --now) '([^']+)'$/", $command, $matches)) {
            $unit = $matches[2];
            if (!isset($this->units[$unit]) || $this->units[$unit]['load'] !== 'loaded') {
                return [5, 'Unit not found'];
            }
            $starting = in_array($matches[1], ['start', 'restart'], true);
            $legacyDnsmasq = is_file($this->root . '/etc/dnsmasq.d/options');
            if ($starting && $unit === 'dnsmasq.service' && $legacyDnsmasq && $this->resolvedStub
                && $this->units['systemd-resolved.service']['active'] === 'active') {
                $this->units[$unit]['active'] = 'failed';

                return [1, 'Job for dnsmasq.service failed: address already in use'];
            }
            if ($starting && $unit === 'systemd-resolved.service') {
                $this->resolvedStub = !($legacyDnsmasq && $this->units['dnsmasq.service']['active'] === 'active');
            }
            match ($matches[1]) {
                'enable' => $this->units[$unit]['enabled'] = 'enabled',
                'disable' => $this->units[$unit]['enabled'] = 'disabled',
                'start', 'restart' => $this->units[$unit]['active'] = 'active',
                'stop' => $this->units[$unit]['active'] = 'inactive',
                'disable --now' => $this->units[$unit] = ['load' => 'loaded', 'active' => 'inactive', 'enabled' => 'disabled', 'preset' => ''],
            };

            return [0, ''];
        }
        if ($command === 'systemctl daemon-reload') {
            return [0, ''];
        }
        if (str_starts_with($command, 'lsattr -d ')) {
            $path = substr(trim(substr($command, 10, -12), "'"), strlen($this->root));

            return [0, (($this->immutable[$path] ?? false) ? '----i---------e-----' : '--------------e-----') . ' ' . $path];
        }
        if (preg_match("/^chattr ([+-])i '(.+)'$/", $command, $matches)) {
            $this->immutable[substr($matches[2], strlen($this->root))] = $matches[1] === '+';

            return [0, ''];
        }
        if (preg_match("/^dpkg-query -S '([^']+)'/", $command, $matches)) {
            $package = $this->packages[$matches[1]] ?? null;

            return $package === null ? [1, ''] : [0, $package['package'] . ': ' . $matches[1] . "\n"];
        }
        if (preg_match("/^dpkg-query -W -f='\\$\\{Conffiles\\}\\\\n' '([^']+)'/", $command, $matches)) {
            $lines = '';
            foreach ($this->packages as $path => $package) {
                if ($package['package'] === $matches[1]) {
                    $lines .= ' ' . $path . ' ' . $package['digest'] . "\n";
                }
            }

            return [0, $lines];
        }
        if (str_starts_with($command, "dpkg-query -W -f='\${Version}'")) {
            return [0, '1.0'];
        }
        if (preg_match("/^cd '([^']+)' && apt-get download/", $command, $matches)) {
            touch($matches[1] . '/network-manager_1.0_amd64.deb');

            return [0, ''];
        }
        if (str_starts_with($command, 'dpkg-deb --fsys-tarfile')) {
            return [0, self::NM_SNIPPET];
        }
        if (preg_match("/^getent ahosts '([^']+)'/", $command, $matches)) {
            return $this->resolve($matches[1]);
        }
        if (str_starts_with($command, 'resolvectl dns')) {
            return [0, "Global:\nLink 2 (eth0): 192.168.1.1\n"];
        }
        if (str_starts_with($command, 'rpm ')) {
            return [127, 'not found'];
        }

        throw new RuntimeException('Unexpected command in test: ' . $command);
    }

    /**
     * Resolver model following glibc: /etc/hosts first, then the nameservers
     * in the file /etc/resolv.conf resolves to (127.0.0.1 when it is missing).
     * 127.0.0.53 is served by systemd-resolved or by a dnsmasq listening on
     * every address; 127.0.0.1 only by dnsmasq.
     *
     * @return array{int, string}
     */
    private function resolve(string $name): array
    {
        if (preg_match('/^(\S+)\t' . preg_quote($name, '/') . '$/m', $this->get('/etc/hosts'), $matches)) {
            return [0, $matches[1] . "     STREAM {$name}\n"];
        }
        $resolv = $this->root . '/etc/resolv.conf';
        $content = is_file($resolv) ? (string) file_get_contents($resolv) : "nameserver 127.0.0.1\n";
        preg_match_all('/^nameserver\s+(\S+)/m', $content, $servers);
        $dnsmasq = $this->units['dnsmasq.service']['active'] === 'active';
        $resolved = $this->units['systemd-resolved.service']['active'] === 'active' && !$this->resolvedBroken;
        $working = false;
        foreach ($servers[1] as $server) {
            $working = $working || match ($server) {
                '127.0.0.53' => $resolved || ($dnsmasq && !is_file($this->root . '/etc/dnsmasq.d/lan.conf')),
                '127.0.0.1' => $dnsmasq,
                default => true,
            };
        }
        if ($name === 'example.com' && $working) {
            return [0, "93.184.215.14   STREAM example.com\n"];
        }

        return [2, ''];
    }

    /**
     * Snapshot of every path below the root except the journal.
     *
     * @return array<string, string>
     */
    private function tree(): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($this->root));
            if (str_starts_with($path, '/var/lib/valet-linux-plus')) {
                continue;
            }
            $result[$path] = match (true) {
                is_link($file->getPathname()) => 'link:' . readlink($file->getPathname()),
                is_dir($file->getPathname()) => sprintf('dir:%o', fileperms($file->getPathname()) & 07777),
                default => sprintf('file:%o:', fileperms($file->getPathname()) & 07777) . file_get_contents($file->getPathname()),
            };
        }
        ksort($result);

        return $result;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }

    private function put(string $path, string $content): void
    {
        file_put_contents($this->root . $path, $content);
    }

    private function get(string $path): string
    {
        return (string) @file_get_contents($this->root . $path);
    }

    private function link(string $path, string $target): void
    {
        symlink($target, $this->root . $path);
    }
}
