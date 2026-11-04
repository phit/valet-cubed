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
use Valet\ResolvedDns;
use Valet\RunAs;
use Valet\Tests\TestCase;

class HostsTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $configuration;
    private Hosts $hosts;
    private ResolvedDns|MockObject $resolvedDns;

    public function setUp(): void
    {
        parent::setUp();

        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->configuration = Mockery::mock(Configuration::class);
        $this->resolvedDns = Mockery::mock(ResolvedDns::class);
        $this->resolvedDns->shouldReceive('activate')->byDefault()->andReturn(false);
        $this->resolvedDns->shouldReceive('deactivate')->byDefault();

        $this->hosts = new Hosts(
            $this->packageManager,
            $this->serviceManager,
            $this->filesystem,
            $this->commandLine,
            $this->configuration,
            $this->resolvedDns
        );
    }

    /**
     * @test
     */
    public function itWillInstallSuccessfully(): void
    {
        $this->expectHostDiscovery('test');

        $this->hosts->install('test');
    }

    public function testDelegatedResolverReplacesOnlyTheManagedHostsBlock(): void
    {
        $this->resolvedDns->shouldReceive('activate')->once()->with('test')->andReturn(true);
        $this->resolvedDns->shouldNotReceive('deactivate');
        $this->filesystem->shouldReceive('exists')->once()->with('/etc/hosts')->andReturnTrue();
        $this->filesystem->shouldReceive('get')->once()->with('/etc/hosts')->andReturn(
            "127.0.0.1 localhost\n\n# Valet Linux+ hosts start\n127.0.0.1 app.test\n# Valet Linux+ hosts end\n"
        );
        $this->expectHostsFileWrite("127.0.0.1 localhost\n");
        $this->hosts->updateDomain('test');
    }

    /**
     * @test
     */
    public function itWillUninstallSuccessfully(): void
    {
        Writer::fake();

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

        $this->expectHostsFileWrite('127.0.0.1 localhost' . PHP_EOL);

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

        $this->expectHostsFileWrite('127.0.0.1 localhost' . PHP_EOL);

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

        $this->hosts->updateDomain('test');
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

        $this->expectHostsFileWrite($expected);
    }

    private function expectHostsFileWrite(string $contents): void
    {
        $temporaryPath = VALET_HOME_PATH . '/hosts.tmp';

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with($temporaryPath, $contents);

        $this->commandLine
            ->shouldReceive('passthru')
            ->once()
            ->with(sprintf(
                'cp %s %s',
                escapeshellarg($temporaryPath),
                escapeshellarg('/etc/hosts')
            ), RunAs::Root);

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with($temporaryPath);
    }
}
