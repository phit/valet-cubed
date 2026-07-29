<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;
use Valet\Hosts;
use Valet\Tests\TestCase;

class HostsTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $configuration;
    private Hosts $hosts;

    public function setUp(): void
    {
        parent::setUp();

        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->configuration = Mockery::mock(Configuration::class);

        $this->hosts = new Hosts(
            $this->packageManager,
            $this->serviceManager,
            $this->filesystem,
            $this->commandLine,
            $this->configuration
        );
    }

    /**
     * @test
     */
    public function itWillInstallSuccessfully(): void
    {
        $this->expectLegacyDnsCleanup();
        $this->expectHostDiscovery('test');

        $this->hosts->install('test');
    }

    /**
     * @test
     */
    public function itWillUninstallSuccessfully(): void
    {
        Writer::fake();

        $this->expectLegacyDnsCleanup();

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with('/etc/hosts')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with('/etc/hosts')
            ->andReturn(implode(PHP_EOL, [
                '127.0.0.1 localhost',
                '',
                '# Valet Linux+ hosts start',
                '# Managed by Valet Linux+. Do not edit between these markers.',
                '127.0.0.1 app.test',
                '# Valet Linux+ hosts end',
                '',
            ]));

        $this->filesystem
            ->shouldReceive('put')
            ->once()
            ->with('/etc/hosts', '127.0.0.1 localhost' . PHP_EOL);

        $this->hosts->uninstall();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString('Valet host entries have been removed', $output->fetch());
    }

    /**
     * @test
     */
    public function itWillStopSuccessfully(): void
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with('/etc/hosts')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with('/etc/hosts')
            ->andReturn(implode(PHP_EOL, [
                '127.0.0.1 localhost',
                '',
                '# Valet Linux+ hosts start',
                '# Managed by Valet Linux+. Do not edit between these markers.',
                '127.0.0.1 app.test',
                '# Valet Linux+ hosts end',
                '',
            ]));

        $this->filesystem
            ->shouldReceive('put')
            ->once()
            ->with('/etc/hosts', '127.0.0.1 localhost' . PHP_EOL);

        $this->hosts->stop();
    }

    /**
     * @test
     */
    public function itWillRestartSuccessfully(): void
    {
        $this->configuration
            ->shouldReceive('get')
            ->once()
            ->with('domain')
            ->andReturn('test');

        $this->expectHostDiscovery('test');

        $this->hosts->restart();
    }

    /**
     * @test
     */
    public function itWillUpdateDomainSuccessfully(): void
    {
        $this->expectHostDiscovery('local');

        $this->hosts->updateDomain('local');
    }

    /**
     * @test
     */
    public function itWillRemoveHostsBlockWhenNoHostsAreServed(): void
    {
        $this->configuration
            ->shouldReceive('get')
            ->once()
            ->with('paths', [])
            ->andReturn([]);

        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites')
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx')
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with('/etc/hosts')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with('/etc/hosts')
            ->andReturn('127.0.0.1 localhost' . PHP_EOL);

        $this->filesystem
            ->shouldReceive('put')
            ->once()
            ->with('/etc/hosts', '127.0.0.1 localhost' . PHP_EOL);

        $this->hosts->updateDomain('test');
    }

    private function expectLegacyDnsCleanup(): void
    {
        $this->serviceManager
            ->shouldReceive('removeValetDns')
            ->once();

        $this->commandLine
            ->shouldReceive('passthru')
            ->once()
            ->with('rm -rf /opt/valet-linux');

        foreach (
            [
                '/etc/dnsmasq.d/valet',
                '/etc/dnsmasq.d/options',
                '/etc/NetworkManager/conf.d/valet.conf',
                '/etc/dnsmasq.d/network-manager',
            ] as $path
        ) {
            $this->filesystem
                ->shouldReceive('unlink')
                ->once()
                ->with($path);
        }

        foreach (
            [
                '/etc/systemd/resolved.conf',
                '/etc/rc.local',
                '/etc/dnsmasq.conf',
            ] as $path
        ) {
            $this->filesystem
                ->shouldReceive('restore')
                ->once()
                ->with($path);
        }

        $this->filesystem
            ->shouldReceive('commentLine')
            ->once()
            ->with('IGNORE_RESOLVCONF', '/etc/default/dnsmasq');

        $this->serviceManager
            ->shouldReceive('isSystemd')
            ->once()
            ->andReturnTrue();

        $this->commandLine
            ->shouldReceive('quietly')
            ->once()
            ->with('sudo systemctl enable --now systemd-resolved');
    }

    private function expectHostDiscovery(string $domain): void
    {
        $this->configuration
            ->shouldReceive('get')
            ->once()
            ->with('paths', [])
            ->andReturn(['/home/developer/code']);

        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with('/home/developer/code')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with('/home/developer/code')
            ->andReturn(['app', 'not-a-site.txt']);

        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with('/home/developer/code/app')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with('/home/developer/code/not-a-site.txt')
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites')
            ->andReturn(['linked']);

        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx')
            ->andReturn(['proxy.' . $domain, 'external.example']);

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with('/etc/hosts')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with('/etc/hosts')
            ->andReturn(implode(PHP_EOL, [
                '127.0.0.1 localhost',
                '',
                '# Valet Linux+ hosts start',
                '# Managed by Valet Linux+. Do not edit between these markers.',
                '127.0.0.1 old.test',
                '# Valet Linux+ hosts end',
                '',
            ]));

        $expected = implode(PHP_EOL, [
            '127.0.0.1 localhost',
            '',
            '# Valet Linux+ hosts start',
            '# Managed by Valet Linux+. Do not edit between these markers.',
            '127.0.0.1' . "\t" . 'app.' . $domain,
            '127.0.0.1' . "\t" . 'linked.' . $domain,
            '127.0.0.1' . "\t" . 'proxy.' . $domain,
            '# Valet Linux+ hosts end',
            '',
        ]);

        $this->filesystem
            ->shouldReceive('put')
            ->once()
            ->with('/etc/hosts', $expected);
    }
}
