<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
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
    private PackageManager&MockInterface $packageManager;
    private ServiceManager&MockInterface $serviceManager;
    private CommandLine&MockInterface $commandLine;
    private Filesystem&MockInterface $filesystem;
    private Configuration&MockInterface $configuration;
    private Hosts $hosts;
    private ResolvedDns&MockInterface $resolvedDns;

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

    #[Test]
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
            "127.0.0.1 localhost\n\n# Valet Cubed hosts start\n127.0.0.1 app.test\n# Valet Cubed hosts end\n"
        );
        $this->expectHostsFileWrite("127.0.0.1 localhost\n");
        $this->hosts->updateDomain('test');
    }

    public function testRemovesTheHostsBlockWrittenBeforeTheRename(): void
    {
        $this->resolvedDns->shouldReceive('activate')->once()->with('test')->andReturn(true);
        $this->filesystem->shouldReceive('exists')->once()->with('/etc/hosts')->andReturnTrue();
        $this->filesystem->shouldReceive('get')->once()->with('/etc/hosts')->andReturn(
            "127.0.0.1 localhost\n\n# Valet Linux+ hosts start\n127.0.0.1 app.test\n# Valet Linux+ hosts end\n"
        );
        $this->expectHostsFileWrite("127.0.0.1 localhost\n");
        $this->hosts->updateDomain('test');
    }

    #[Test]
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
                '# Valet Cubed hosts start',
                '# Managed by Valet Cubed. Do not edit between these markers.',
                '127.0.0.1 app.test',
                '# Valet Cubed hosts end',
                '',
            ]));

        $this->expectHostsFileWrite('127.0.0.1 localhost' . PHP_EOL);

        $this->hosts->uninstall();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString('Valet host entries have been removed', $output->fetch());
    }

    #[Test]
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
                '# Valet Cubed hosts start',
                '# Managed by Valet Cubed. Do not edit between these markers.',
                '127.0.0.1 app.test',
                '# Valet Cubed hosts end',
                '',
            ]));

        $this->expectHostsFileWrite('127.0.0.1 localhost' . PHP_EOL);

        $this->hosts->stop();
    }

    #[Test]
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

    #[Test]
    public function itWillUpdateDomainSuccessfully(): void
    {
        $this->expectHostDiscovery('local');

        $this->hosts->updateDomain('local');
    }

    #[Test]
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
                '# Valet Cubed hosts start',
                '# Managed by Valet Cubed. Do not edit between these markers.',
                '127.0.0.1 old.test',
                '# Valet Cubed hosts end',
                '',
            ]));

        $expected = implode(PHP_EOL, [
            '127.0.0.1 localhost',
            '',
            '# Valet Cubed hosts start',
            '# Managed by Valet Cubed. Do not edit between these markers.',
            '127.0.0.1' . "\t" . 'app.' . $domain,
            '127.0.0.1' . "\t" . 'linked.' . $domain,
            '127.0.0.1' . "\t" . 'proxy.' . $domain,
            '# Valet Cubed hosts end',
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
