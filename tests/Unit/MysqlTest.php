<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;
use Valet\Mysql;
use Valet\PackageManagers\Apt;
use Valet\PhpFpm;
use Valet\RunAs;
use Valet\Tests\TestCase;

use function Valet\swap;

class MysqlTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $config;
    private Mysql $mysql;

    public function setUp(): void
    {
        parent::setUp();

        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->once()
            ->andReturn('mariadb-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mariadb-server')
            ->once()
            ->andReturnFalse();

        $this->mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );
    }

    public static function packageDataProvider(): array
    {
        return [
            'mysql' => [
                false,
                'mysql',
                'mysql-server',
            ],
            'mariadb' => [
                true,
                'mariadb',
                'mariadb-server',
            ],
        ];
    }

    public function testExportKeepsCredentialsOutOfCommandAndRemovesTemporaryFile(): void
    {
        $password = 'secret "quoted"';
        $this->config->shouldReceive('get')->with('mysql', [])->once()
            ->andReturn(['user' => 'valet', 'password' => $password]);
        $clientConfig = null;
        $this->commandLine->shouldReceive('run')->once()->andReturnUsing(function ($command) use (&$clientConfig, $password) {
            $this->assertStringNotContainsString($password, $command);
            preg_match("/--defaults-extra-file='([^']+)'/", $command, $matches);
            $clientConfig = $matches[1];
            $this->assertSame(0600, fileperms($clientConfig) & 0777);
            $this->assertStringContainsString('password="secret \\"quoted\\""', file_get_contents($clientConfig));
            return '';
        });

        $this->mysql->exportDatabase('example', true);
        $this->assertNotNull($clientConfig);
        $this->assertFileDoesNotExist($clientConfig);
    }

    #[Test]
    #[DataProvider('packageDataProvider')]
    public function itWillInstallSuccessfully(bool $useMariaDB, string $packageName, string $packageServerName): void
    {
        Writer::fake();
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);
        $phpFpm->shouldReceive('getCurrentVersion')->once()->andReturn('8.2');
        $this->packageManager->shouldReceive('getPhpExtensionName')->with('8.2', 'mysql')->once()->andReturn('php8.2-mysql');

        $this->packageManager
            ->shouldReceive('packageName')
            ->with($packageName)
            ->once()
            ->andReturn($packageServerName);

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->with('php8.2-mysql')
            ->once();

        $this->packageManager
            ->shouldReceive('installed')
            ->with($packageServerName)
            ->once()
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('installOrFail')
            ->with($packageServerName)
            ->once()
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->twice()
            ->andReturn('mariadb-server');

        $this->serviceManager
            ->shouldReceive('enable')
            ->with($packageName)
            ->once()
            ->andReturnFalse();

        $this->serviceManager
            ->shouldReceive('restart')
            ->with($packageName)
            ->once();

        $this->commandLine
            ->shouldReceive('run')
            ->with('mysqladmin --silent ping', Mockery::type('callable'), RunAs::Root)
            ->once()
            ->andReturn('');

        $this->commandLine
            ->shouldReceive('run')
            ->with(Mockery::pattern('/^mysql < /'), Mockery::type('callable'), RunAs::Root)
            ->once()
            ->andReturnUsing(function (string $command) {
                $sql = file_get_contents(trim(substr($command, strlen('mysql < ')), "'"));
                // MySQL 8.4 no longer loads mysql_native_password.
                $this->assertStringContainsString("CREATE USER 'valet'@'localhost' IDENTIFIED BY ''", $sql);

                return '';
            });

        $this->config
            ->shouldReceive('get')
            ->with('mysql', [])
            ->once()
            ->andReturn([]);

        $this->config
            ->shouldReceive('set')
            ->with('mysql', ['user' => 'valet', 'password' => ''])
            ->once()
            ->andReturn([]);

        $this->mysql->install($useMariaDB);
    }

    #[Test]
    public function itWillInstallMariaDbWhenAptOffersNoMysqlServer(): void
    {
        Writer::fake();
        $apt = Mockery::mock(Apt::class);
        $apt->shouldReceive('packageName')->with('mysql')->andReturn('mysql-server');
        $apt->shouldReceive('packageName')->with('mariadb')->andReturn('mariadb-server');
        $apt->shouldReceive('installed')->with('mysql-server')->andReturnFalse();
        $apt->shouldReceive('installed')->with('mariadb-server')->andReturnFalse();
        $apt->shouldReceive('hasInstallCandidate')->with('mysql-server')->once()->andReturnFalse();
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);
        $phpFpm->shouldReceive('getCurrentVersion')->andReturn('8.4');
        $apt->shouldReceive('getPhpExtensionName')->with('8.4', 'mysql')->andReturn('php8.4-mysql');
        $apt->shouldReceive('ensureInstalled')->with('php8.4-mysql')->once();
        $apt->shouldReceive('installOrFail')->with('mariadb-server')->once();
        $apt->shouldNotReceive('installOrFail')->with('mysql-server');
        $this->serviceManager->shouldReceive('enable')->with('mariadb')->once();
        $this->serviceManager->shouldReceive('restart')->with('mariadb')->once();
        $this->commandLine->shouldReceive('run')->andReturn('');
        $this->config->shouldReceive('get')->with('mysql', [])->andReturn([]);
        $this->config->shouldReceive('set')->with('mysql', ['user' => 'valet', 'password' => ''])->once();

        (new Mysql($apt, $this->serviceManager, $this->commandLine, $this->filesystem, $this->config))->install();
    }

    #[Test]
    public function itWillInstallMysqlWhenAptOffersIt(): void
    {
        Writer::fake();
        $apt = Mockery::mock(Apt::class);
        $apt->shouldReceive('packageName')->with('mysql')->andReturn('mysql-server');
        $apt->shouldReceive('packageName')->with('mariadb')->andReturn('mariadb-server');
        $apt->shouldReceive('installed')->andReturnFalse();
        $apt->shouldReceive('hasInstallCandidate')->with('mysql-server')->once()->andReturnTrue();
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);
        $phpFpm->shouldReceive('getCurrentVersion')->andReturn('8.4');
        $apt->shouldReceive('getPhpExtensionName')->andReturn('php8.4-mysql');
        $apt->shouldReceive('ensureInstalled');
        $apt->shouldReceive('installOrFail')->with('mysql-server')->once();
        $this->serviceManager->shouldReceive('enable')->with('mysql')->once();
        $this->serviceManager->shouldReceive('restart')->with('mysql')->once();
        $this->commandLine->shouldReceive('run')->andReturn('');
        $this->config->shouldReceive('get')->andReturn([]);
        $this->config->shouldReceive('set');

        (new Mysql($apt, $this->serviceManager, $this->commandLine, $this->filesystem, $this->config))->install();
    }

    #[Test]
    public function itWillNotOverrideWhenInstalled()
    {
        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnTrue();

        $mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnTrue();

        $this->config
            ->shouldReceive('get')
            ->with('mysql', [])
            ->once()
            ->andReturn(['user' => 'valet', 'password' => 'valet-password']);

        $this->packageManager
            ->shouldNotReceive('installOrFail')
            ->with('mysql-server');
        $this->serviceManager
            ->shouldNotReceive('enable')
            ->with('mysql');

        $mysql->install();
    }

    #[Test]
    public function itWillNotOverrideWhenMariaDbInstalled()
    {
        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->once()
            ->andReturn('mariadb-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mariadb-server')
            ->once()
            ->andReturnTrue();

        $mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mariadb-server')
            ->once()
            ->andReturnTrue();

        $this->config
            ->shouldReceive('get')
            ->with('mysql', [])
            ->once()
            ->andReturn(['user' => 'valet', 'password' => 'valet-password']);

        $this->packageManager
            ->shouldNotReceive('installOrFail')
            ->with('mariadb-server');
        $this->serviceManager
            ->shouldNotReceive('enable')
            ->with('mariadb');

        $mysql->install(true);
    }

    #[Test]
    public function itWillStopService(): void
    {
        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnTrue();

        $mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->once()
            ->andReturn('mariadb-server');

        $this->serviceManager
            ->shouldReceive('stop')
            ->with('mysql')
            ->once()
            ->andReturnTrue();

        $mysql->stop();
    }

    #[Test]
    public function itWillRestartService(): void
    {
        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnTrue();

        $mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->once()
            ->andReturn('mariadb-server');

        $this->serviceManager
            ->shouldReceive('restart')
            ->with('mysql')
            ->once()
            ->andReturnTrue();

        $mysql->restart();
    }

    #[Test]
    public function itWillUninstallService(): void
    {
        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnTrue();

        $mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->once()
            ->andReturn('mariadb-server');

        $this->serviceManager
            ->shouldReceive('stop')
            ->with('mysql')
            ->once()
            ->andReturnTrue();

        $mysql->uninstall();
    }
}
