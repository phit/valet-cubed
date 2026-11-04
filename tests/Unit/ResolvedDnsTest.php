<?php

namespace Valet\Tests\Unit;

use Mockery;
use Valet\CommandLine;
use Valet\Filesystem;
use Valet\ResolvedDns;
use Valet\Tests\TestCase;

class ResolvedDnsTest extends TestCase
{
    private array $files = [];
    private array $commands = [];
    private string $version = 'systemd 259';
    private string $resolver = '/run/systemd/resolve/stub-resolv.conf';
    private string $binary = '/usr/sbin/dnsmasq';
    private string $resolverStatus = 'active';
    private ?string $failure = null;
    private string $probeAnswer = '127.0.0.1';
    private int $staleProbes = 0;
    private ResolvedDns $dns;

    public function setUp(): void
    {
        parent::setUp();
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('exists')->byDefault()->andReturnUsing(function ($path) {
            return $path === '/etc/resolv.conf' || isset($this->files[$path]);
        });
        $filesystem->shouldReceive('realpath')->byDefault()->andReturnUsing(fn () => $this->resolver);
        $filesystem->shouldReceive('get')->byDefault()->andReturnUsing(fn ($path) => $this->files[$path]);
        $filesystem->shouldReceive('put')->byDefault()->andReturnUsing(function ($path, $contents) {
            $this->files[$path] = $contents;
            return (string) strlen($contents);
        });
        $filesystem->shouldReceive('unlink')->byDefault()->andReturnUsing(function ($path) {
            unset($this->files[$path]);
        });
        $filesystem->shouldReceive('ensureDirExists')->byDefault();

        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')->byDefault()->andReturnUsing(function ($command, $onError = null) {
            $this->commands[] = $command;
            if ($command === $this->failure && $onError !== null) {
                $onError(1, 'failed');
            }
            if (str_starts_with($command, 'resolvectl query --type=A --cache=no ')) {
                return $this->staleProbes-- > 0 ? 'resolve call failed: No appropriate name servers or networks' : $this->probeAnswer;
            }
            return match ($command) {
                'systemctl --version 2>/dev/null' => $this->version,
                'systemctl is-active systemd-resolved.service 2>/dev/null' => $this->resolverStatus,
                'command -v dnsmasq 2>/dev/null' => $this->binary,
                default => '',
            };
        });
        $this->dns = new ResolvedDns($filesystem, $cli, fn () => null);
    }

    public function testActivatesOnlyTheConfiguredDomainAndCleansUpItsOwnFiles(): void
    {
        $this->assertTrue($this->dns->activate('test'));
        $this->assertStringContainsString('DNS=127.0.0.2:53535' . "\nDomains=~test\nDefaultRoute=no", $this->files[ResolvedDns::DELEGATE]);
        $this->assertStringContainsString('--no-resolv --no-hosts', $this->files[ResolvedDns::UNIT]);
        $this->assertStringContainsString('--local=/test/ --address=/test/127.0.0.1', $this->files[ResolvedDns::UNIT]);
        $this->assertContains('systemctl reload systemd-resolved.service', $this->commands);

        $this->dns->deactivate();
        $this->assertArrayNotHasKey(ResolvedDns::DELEGATE, $this->files);
        $this->assertArrayNotHasKey(ResolvedDns::UNIT, $this->files);
    }

    public function testReplacesTheResolverWrittenBeforeTheRename(): void
    {
        $this->files[ResolvedDns::FORMER_UNIT] = "# Managed by Valet Linux+\n[Unit]\n";
        $this->files[ResolvedDns::FORMER_DELEGATE] = "# Managed by Valet Linux+\n[Delegate]\n";

        $this->assertTrue($this->dns->activate('test'));

        $this->assertArrayNotHasKey(ResolvedDns::FORMER_UNIT, $this->files);
        $this->assertArrayNotHasKey(ResolvedDns::FORMER_DELEGATE, $this->files);
        $this->assertArrayHasKey(ResolvedDns::UNIT, $this->files);
        // The former service holds the same port, so it stops before the new one starts.
        $stopped = array_search('systemctl disable --now valet-linux-plus-dns.service', $this->commands, true);
        $started = array_search('systemctl restart valet-cubed-dns.service', $this->commands, true);
        $this->assertIsInt($stopped);
        $this->assertIsInt($started);
        $this->assertLessThan($started, $stopped);
    }

    public function testKeepsUnownedFilesWithTheFormerNames(): void
    {
        $this->files[ResolvedDns::FORMER_UNIT] = 'user configuration';

        $this->assertTrue($this->dns->activate('test'));
        $this->dns->deactivate();

        $this->assertSame('user configuration', $this->files[ResolvedDns::FORMER_UNIT]);
        $this->assertNotContains('systemctl disable --now valet-linux-plus-dns.service', $this->commands);
    }

    public function testUnsupportedOrNonActiveResolverFallsBackWithoutChangingDns(): void
    {
        $this->version = 'systemd 257';
        $this->assertFalse($this->dns->activate('test'));
        $this->assertSame([], $this->files);
        $this->version = 'systemd 259';
        $this->resolver = '/run/NetworkManager/resolv.conf';
        $this->assertFalse($this->dns->activate('test'));
        $this->assertSame([], $this->files);
    }

    public function testMissingDnsmasqFallsBackWithoutChangingDns(): void
    {
        $this->binary = '';
        $this->assertFalse($this->dns->activate('test'));
        $this->assertSame([], $this->files);
        $this->assertNotContains('systemctl daemon-reload', $this->commands);
    }

    public function testDoesNotOverwriteAnExistingUnownedService(): void
    {
        $this->files[ResolvedDns::UNIT] = 'user configuration';
        $this->assertFalse($this->dns->activate('test'));
        $this->dns->deactivate();
        $this->assertSame('user configuration', $this->files[ResolvedDns::UNIT]);
    }

    public function testDoesNotDelegateLocalOrInvalidDomains(): void
    {
        $this->assertFalse($this->dns->activate('local'));
        $this->assertFalse($this->dns->activate('localhost'));
        $this->assertFalse($this->dns->activate("test\nDNS=1.1.1.1"));
        $this->assertSame([], $this->files);
    }

    public function testChangingDomainReplacesThePreviousDelegation(): void
    {
        $this->assertTrue($this->dns->activate('test'));
        $this->assertTrue($this->dns->activate('devtest'));
        $this->assertStringContainsString('Domains=~devtest', $this->files[ResolvedDns::DELEGATE]);
        $this->assertStringNotContainsString('Domains=~test', $this->files[ResolvedDns::DELEGATE]);
        $this->assertStringContainsString('--address=/devtest/127.0.0.1', $this->files[ResolvedDns::UNIT]);
    }

    public function testRollsBackWhenStartingTheResolverFails(): void
    {
        $this->failure = 'systemctl restart valet-cubed-dns.service';
        $this->assertFalse($this->dns->activate('test'));
        $this->assertSame([], $this->files);
        $this->assertContains('systemctl disable --now valet-cubed-dns.service', $this->commands);
    }

    public function testRollsBackWhenDelegatedLookupDoesNotResolveLocally(): void
    {
        $this->probeAnswer = '';
        $this->assertFalse($this->dns->activate('test'));
        $this->assertSame([], $this->files);
    }

    public function testWaitsForResolvedToApplyTheDelegationAfterAReload(): void
    {
        // resolved applies a reload asynchronously; the first queries can miss.
        $this->staleProbes = 3;
        $this->assertTrue($this->dns->activate('test'));
        $this->assertArrayHasKey(ResolvedDns::DELEGATE, $this->files);
    }

    public function testLeavesResponderRunningIfResolverCannotReloadDuringCleanup(): void
    {
        $this->assertTrue($this->dns->activate('test'));
        $this->commands = [];
        $this->failure = 'systemctl reload systemd-resolved.service';
        try {
            $this->dns->deactivate();
            $this->fail('Expected a reload error');
        } catch (\RuntimeException $exception) {
            $this->assertArrayHasKey(ResolvedDns::UNIT, $this->files);
            $this->assertNotContains('systemctl disable --now valet-cubed-dns.service', $this->commands);
        }
    }

    public function testRepeatedActivationDoesNotReloadTheResolver(): void
    {
        $this->assertTrue($this->dns->activate('test'));
        $this->commands = [];
        $this->assertTrue($this->dns->activate('test'));
        $this->assertNotContains('systemctl reload systemd-resolved.service', $this->commands);
        $this->assertNotContains('systemctl restart valet-cubed-dns.service', $this->commands);
    }

    public function testCleanupDoesNotReloadAnInactiveSystemResolver(): void
    {
        $this->assertTrue($this->dns->activate('test'));
        $this->resolverStatus = 'inactive';
        $this->commands = [];
        $this->dns->deactivate();
        $this->assertSame([], $this->files);
        $this->assertNotContains('systemctl reload systemd-resolved.service', $this->commands);
    }
}
