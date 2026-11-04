<?php

namespace Valet\Tests\Unit;

use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;
use Valet\Mailpit;
use Valet\RunAs;
use Valet\SiteProxy;
use Valet\SiteSecure;
use Valet\Tests\TestCase;

use function Valet\swap;

class MailpitTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $config;
    private SiteProxy|MockObject $siteProxy;
    private SiteSecure|MockObject $siteSecure;
    private Mailpit $mailpit;

    public function setUp(): void
    {
        parent::setUp();

        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->commandLine = Mockery::mock(CommandLine::class);

        $this->config = Mockery::mock(Configuration::class);
        swap(Configuration::class, $this->config);

        $this->siteProxy = Mockery::mock(SiteProxy::class);
        swap(SiteProxy::class, $this->siteProxy);

        $this->siteSecure = Mockery::mock(SiteSecure::class);
        swap(SiteSecure::class, $this->siteSecure);

        $this->mailpit = new Mailpit(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem
        );
    }

    /**
     * @test
     */
    public function itWillInstallSuccessfully(): void
    {
        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($command) {
                $this->assertSame('which mailpit', $command);
                return false;
            });

        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with('curl -sL https://raw.githubusercontent.com/axllent/mailpit/develop/install.sh | bash');

        $this->serviceManager
            ->shouldReceive('isSystemd')
            ->once()
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_ROOT_PATH . '/cli/stubs/init/mailpit')
            ->andReturn('service file content');

        $this->filesystem
            ->shouldReceive('put')
            ->once()
            ->with('/etc/systemd/system/mailpit.service', 'service file content');

        $this->serviceManager
            ->shouldReceive('enable')
            ->once()
            ->with('mailpit');

        $this->config
            ->shouldReceive('get')
            ->twice()
            ->with('domain')
            ->andReturn('test');

        $this->siteProxy
            ->shouldReceive('proxyCreate')
            ->once()
            ->with('mails.test', 'http://localhost:8025', true);

        $this->serviceManager
            ->shouldReceive('start')
            ->once()
            ->with('mailpit');

        // MailHog from releases before 2.0, with the site but not its certificate.
        $this->filesystem->shouldReceive('exists')->with('/etc/systemd/system/mailhog.service')->once()->andReturnTrue();
        $this->filesystem->shouldReceive('exists')->with('/etc/init.d/mailhog')->once()->andReturnFalse();
        $this->serviceManager->shouldReceive('stop')->with('mailhog')->once();
        $this->serviceManager->shouldReceive('disable')->with('mailhog')->once();
        $this->filesystem->shouldReceive('unlink')->with('/etc/systemd/system/mailhog.service')->once();
        $this->commandLine->shouldReceive('run')->with('systemctl daemon-reload', null, RunAs::Root)->once();
        $this->filesystem->shouldReceive('unlink')->with('/opt/valet-linux/mailhog')->once();
        $this->filesystem->shouldReceive('unlink')->with(VALET_HOME_PATH . '/Nginx/mailhog.test')->once();
        foreach (['crt', 'key', 'csr', 'conf'] as $extension) {
            $this->filesystem->shouldReceive('unlink')->with(VALET_HOME_PATH . '/Certificates/mailhog.test.' . $extension)->once();
        }

        $this->mailpit->install();
    }

    /**
     * @test
     */
    public function itWillStartServiceSuccessfully(): void
    {
        $this->serviceManager
            ->shouldReceive('start')
            ->once()
            ->with('mailpit');

        $this->mailpit->start();
    }

    /**
     * @test
     */
    public function itWillRestartServiceSuccessfully(): void
    {
        $this->serviceManager
            ->shouldReceive('restart')
            ->once()
            ->with('mailpit');

        $this->mailpit->restart();
    }

    /**
     * @test
     */
    public function itWillStopServiceSuccessfully(): void
    {
        $this->serviceManager
            ->shouldReceive('stop')
            ->once()
            ->with('mailpit');

        $this->mailpit->stop();
    }

    /**
     * @test
     */
    public function itWillPrintStatus(): void
    {
        $this->serviceManager
            ->shouldReceive('printStatus')
            ->once()
            ->with('mailpit');

        $this->mailpit->status();
    }

    /**
     * @test
     */
    public function itWillUninstallServiceSuccessfully(): void
    {
        $this->serviceManager
            ->shouldReceive('stop')
            ->once()
            ->with('mailpit');

        $this->mailpit->uninstall();
    }
}
