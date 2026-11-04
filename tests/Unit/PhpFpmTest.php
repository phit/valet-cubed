<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\DevTools;
use Valet\Filesystem;
use Valet\Nginx;
use Valet\PhpFpm;
use Valet\Site;
use Valet\Tests\TestCase;

use function Valet\group;
use function Valet\swap;
use function Valet\user;

class PhpFpmTest extends TestCase
{
    private PackageManager&MockInterface $packageManager;
    private ServiceManager&MockInterface $serviceManager;
    private CommandLine&MockInterface $commandLine;
    private Filesystem&MockInterface $filesystem;
    private Configuration&MockInterface $config;
    private Site&MockInterface $site;
    private Nginx&MockInterface $nginx;
    private PhpFpm $phpFpm;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = Mockery::mock(Configuration::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->packageManager->shouldReceive('getPhpFpmServiceName')->byDefault()->andReturnUsing(fn ($version) => 'php' . $version . '-fpm');
        $this->packageManager->shouldReceive('getPhpExtensionName')->byDefault()->andReturnUsing(fn ($version, $extension) => 'php' . $version . '-' . $extension);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->site = Mockery::mock(Site::class);
        $this->nginx = Mockery::mock(Nginx::class);

        $this->phpFpm = new PhpFpm(
            $this->config,
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->site,
            $this->nginx
        );
    }

    public static function versionProvider(): array
    {
        return [
            [
                '8.2',
                '8.2',
                'valet82.sock',
                '8.0',
            ],
            [
                '82',
                '8.2',
                'valet82.sock',
                '8.0',
            ],
            [
                'php-8.2',
                '8.2',
                'valet82.sock',
                '8.0',
            ],
            [
                'php8.2',
                '8.2',
                'valet82.sock',
                '8.0',
            ],
            [
                'php@8.2',
                '8.2',
                'valet82.sock',
                '8.0',
            ],
            [
                '8.3',
                '8.3',
                'valet83.sock',
                '8.0',
            ],
            [
                '83',
                '8.3',
                'valet83.sock',
                '8.0',
            ],
            [
                'php-8.3',
                '8.3',
                'valet83.sock',
                '8.0',
            ],
            [
                'php8.3',
                '8.3',
                'valet83.sock',
                '8.0',
            ],
            [
                'php@8.3',
                '8.3',
                'valet83.sock',
                '8.0',
            ],
        ];
    }

    #[Test]
    #[DataProvider('versionAndSocketProvider')]
    public function itWillInstallSuccessfully(
        string $version,
        string $expectedVersion,
        string $expectedSocketFileName
    ): void {
        $fpmName = \sprintf('php%s-fpm', $expectedVersion);
        $prefix = \sprintf('php%s-', $expectedVersion);
        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->once()
            ->with($expectedVersion)
            ->andReturn($fpmName);

        $this->packageManager
            ->shouldReceive('installed')
            ->once()
            ->with($fpmName)
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->once()
            ->with($fpmName)
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->once()
            ->with(
                \sprintf(
                    '%1$scli %1$smysql %1$sgd %1$szip %1$sxml %1$scurl %1$smbstring %1$spgsql %1$sintl %1$sposix',
                    $prefix
                )
            );

        $this->serviceManager
            ->shouldReceive('enable')
            ->once()
            ->with($fpmName);

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with('/var/log', user());

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_ROOT_PATH . '/cli/stubs/fpm.conf')
            ->andReturn('fpm.conf content VALET_USER VALET_GROUP VALET_FPM_SOCKET_FILE');

        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with('/etc/php/' . $expectedVersion . '/fpm/pool.d')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(
                '/etc/php/' . $expectedVersion . '/fpm/pool.d/valet.conf',
                \sprintf('fpm.conf content %s %s %s', user(), group(), VALET_HOME_PATH . '/' . $expectedSocketFileName),
            )
            ->andReturn('fpm.conf content VALET_USER VALET_GROUP VALET_FPM_SOCKET_FILE');

        $this->serviceManager
            ->shouldReceive('restart')
            ->once()
            ->with($fpmName);

        $this->phpFpm->install($version);
    }

    #[Test]
    #[DataProvider('versionPairProvider')]
    public function itWillUninstallSuccessfully(string $version, string $expectedVersion): void
    {
        $this->filesystem
            ->shouldReceive('isDir')
            ->with('/etc/php/' . $expectedVersion . '/fpm/pool.d')
            ->once()
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('exists')
            ->with('/etc/php/' . $expectedVersion . '/fpm/pool.d/valet.conf')
            ->once()
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('unlink')
            ->with('/etc/php/' . $expectedVersion . '/fpm/pool.d/valet.conf')
            ->once();

        $this->packageManager
            ->shouldReceive('getPhpFpmServiceName')
            ->with($expectedVersion)
            ->once()
            ->andReturn('php' . $expectedVersion . '-fpm');

        $this->serviceManager
            ->shouldReceive('stop')
            ->with('php' . $expectedVersion . '-fpm')
            ->once();

        $this->phpFpm->uninstall($version);
    }

    #[Test]
    #[DataProvider('versionProvider')]
    public function itWillSwitchVersionSuccessfully(
        string $version,
        string $expectedVersion,
        string $expectedSocketFileName,
        string $currentVersion
    ): void {
        Writer::fake();
        $this->config
            ->shouldReceive('get')
            ->with('php_version', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION)
            ->once()
            ->andReturn($currentVersion);

        $fpmName = \sprintf('php%s-fpm', $expectedVersion);
        $prefix = \sprintf('php%s-', $expectedVersion);
        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->once()
            ->with($expectedVersion)
            ->andReturn($fpmName);

        $this->packageManager
            ->shouldReceive('installed')
            ->once()
            ->with($fpmName)
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->once()
            ->with($fpmName)
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->once()
            ->with(
                \sprintf(
                    '%1$scli %1$smysql %1$sgd %1$szip %1$sxml %1$scurl %1$smbstring %1$spgsql %1$sintl %1$sposix',
                    $prefix
                )
            );

        $this->serviceManager
            ->shouldReceive('enable')
            ->once()
            ->with($fpmName);

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with('/var/log', user());

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_ROOT_PATH . '/cli/stubs/fpm.conf')
            ->andReturn('fpm.conf content VALET_USER VALET_GROUP VALET_FPM_SOCKET_FILE');

        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with(
                '/etc/php/' . $expectedVersion . '/fpm/pool.d',
            )
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(
                '/etc/php/' . $expectedVersion . '/fpm/pool.d/valet.conf',
                \sprintf('fpm.conf content %s %s %s', user(), group(), VALET_HOME_PATH . '/' . $expectedSocketFileName),
            )
            ->andReturn('fpm.conf content VALET_USER VALET_GROUP VALET_FPM_SOCKET_FILE');

        $this->serviceManager
            ->shouldReceive('restart')
            ->once()
            ->with($fpmName);

        $this->serviceManager
            ->expects('disabled')
            ->once()
            ->with($fpmName)
            ->andReturnTrue();

        $this->serviceManager
            ->expects('enable')
            ->once()
            ->with($fpmName);

        $this->config
            ->shouldReceive('set')
            ->once()
            ->with('php_version', $expectedVersion);

        $configuredSites = [
            'mails.test' => [
                'socket_file' => 'valet74.sock',
            ],
            'site1.test' => [
                'socket_file' => 'valet73.sock',
            ],
            'valetsite.test' => [
                'socket_file' => 'valet70.sock',
            ],
        ];
        $this->nginx
            ->shouldReceive('configuredSites')
            ->twice()
            ->andReturn(collect(array_keys($configuredSites)));

        foreach ($configuredSites as $configuredSite => $siteData) {
            $this->filesystem
                ->shouldReceive('get')
                ->twice()
                ->with(VALET_HOME_PATH . '/Nginx/' . $configuredSite)
                ->andReturn('content unix:' . $siteData['socket_file']);

            $this->filesystem
                ->shouldReceive('put')
                ->once()
                ->with(
                    VALET_HOME_PATH . '/Nginx/' . $configuredSite,
                    'content unix:' . VALET_HOME_PATH . '/' . $expectedSocketFileName
                );
        }

        $this->config
            ->shouldReceive('get')
            ->with('php_version', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION)
            ->once()
            ->andReturn($expectedVersion);

        $this->packageManager
            ->shouldReceive('getPhpFpmServiceName')
            ->with($currentVersion)
            ->once()
            ->andReturn('php' . $currentVersion . '-fpm');

        $this->serviceManager
            ->shouldReceive('stop')
            ->with('php' . $currentVersion . '-fpm')
            ->once();

        $this->nginx
            ->shouldReceive('installServer')
            ->once()
            ->andReturn($expectedVersion);

        $this->nginx
            ->shouldReceive('restart')
            ->once();

        $this->serviceManager
            ->shouldReceive('printStatus')
            ->with($fpmName)
            ->once();

        $this->commandLine
            ->shouldReceive('run')
            ->with('update-alternatives --set php /usr/bin/php' . $expectedVersion)
            ->once();

        $this->phpFpm->switchVersion($version, true);
    }

    #[Test]
    #[DataProvider('versionPairProvider')]
    public function itWillRestartSuccessfully(string $version, string $expectedVersion): void
    {
        $fpmName = 'php' . $expectedVersion . '-fpm';

        $this->packageManager
            ->shouldReceive('getPhpFpmServiceName')
            ->with($expectedVersion)
            ->andReturn($fpmName);

        $this->serviceManager
            ->shouldReceive('restart')
            ->with($fpmName)
            ->once();

        $this->phpFpm->restart($expectedVersion);
    }

    #[Test]
    #[DataProvider('versionPairProvider')]
    public function itWillStopSuccessfully(string $version, string $expectedVersion): void
    {
        $fpmName = 'php' . $expectedVersion . '-fpm';

        $this->packageManager
            ->shouldReceive('getPhpFpmServiceName')
            ->with($expectedVersion)
            ->andReturn($fpmName);

        $this->serviceManager
            ->shouldReceive('stop')
            ->with($fpmName)
            ->once();

        $this->phpFpm->stop($expectedVersion);
    }

    #[Test]
    #[DataProvider('versionPairProvider')]
    public function itWillGetStatusSuccessfully(string $version, string $expectedVersion): void
    {
        $fpmName = 'php' . $expectedVersion . '-fpm';

        $this->packageManager
            ->shouldReceive('getPhpFpmServiceName')
            ->with($expectedVersion)
            ->andReturn($fpmName);

        $this->serviceManager
            ->shouldReceive('printStatus')
            ->with($fpmName)
            ->once();

        $this->phpFpm->status($expectedVersion);
    }

    public static function socketFileVersionProvider(): array
    {
        return [
            [
                '8.2',
                'valet82.sock',
            ],
            [
                '8.1',
                'valet81.sock',
            ],
            [
                '8.3',
                'valet83.sock',
            ],
        ];
    }

    #[Test]
    #[DataProvider('socketFileVersionProvider')]
    public function itWillGetSocketFileName(string $version, string $expectedSocket): void
    {
        $socketFile = $this->phpFpm->socketFileName($version);

        $this->assertSame($expectedSocket, $socketFile);
    }

    #[Test]
    #[DataProvider('versionPairProvider')]
    public function itWillNormalizePhpVersion(string $phpVersion, string $expectedVersion): void
    {
        $normalizedVersion = $this->phpFpm->normalizePhpVersion($phpVersion);

        $this->assertSame($expectedVersion, $normalizedVersion);
    }

    public static function invalidVersionProvider(): array
    {
        return [
            [
                'invalid',
            ],
            [
                '8',
            ],
            [
                'invalid-8.0',
            ],
            [
                '8;2',
            ],
        ];
    }

    public function testNormalizesTwoDigitMinorVersion(): void
    {
        $this->assertSame('8.10', $this->phpFpm->normalizePhpVersion('php8.10'));
    }

    #[Test]
    #[DataProvider('invalidVersionProvider')]
    public function itWillReturnBlankStringWhenInvalidStringGiven(string $version): void
    {
        $normalizedVersion = $this->phpFpm->normalizePhpVersion($version);

        $this->assertSame('', $normalizedVersion);
    }

    /**
     * The requested and normalized version from versionProvider.
     */
    public static function versionPairProvider(): array
    {
        return array_map(fn (array $row) => array_slice($row, 0, 2), self::versionProvider());
    }

    /**
     * The requested and normalized version, and the socket file, from versionProvider.
     */
    public static function versionAndSocketProvider(): array
    {
        return array_map(fn (array $row) => array_slice($row, 0, 3), self::versionProvider());
    }

    /**
     * The versions from executableVersionProvider.
     */
    public static function executableVersionOnlyProvider(): array
    {
        return array_map(fn (array $row) => array_slice($row, 0, 1), self::executableVersionProvider());
    }

    public static function executableVersionProvider(): array
    {
        return [
            [
                '8.1',
                'valet81.sock',
            ],
            [
                '8.2',
                'valet82.sock',
            ],
        ];
    }

    #[Test]
    #[DataProvider('executableVersionOnlyProvider')]
    public function itWillGetExecutablePath(string $version): void
    {
        $devTools = Mockery::mock(DevTools::class);
        swap(DevTools::class, $devTools);

        $devTools->shouldReceive('getBin')
            ->with('php' . $version, ['/usr/local/bin/php'])
            ->once()
            ->andReturn('/usr/bin/php' . $version);

        $binFile = $this->phpFpm->getPhpExecutablePath($version);

        $this->assertSame('/usr/bin/php' . $version, $binFile);
    }

    #[Test]
    public function itFallsBackToConfiguredBinaryWhenDefaultPhpIsIgnored(): void
    {
        $devTools = Mockery::mock(DevTools::class);
        swap(DevTools::class, $devTools);

        $devTools->shouldReceive('getBin')
            ->with('php', ['/usr/local/bin/php'])
            ->once()
            ->andReturn(false);

        $this->config->shouldReceive('get')
            ->with('fallback_binary')
            ->once()
            ->andReturn('/usr/bin/php8.4');

        $this->filesystem->shouldReceive('exists')
            ->with('/usr/bin/php8.4')
            ->once()
            ->andReturn(true);

        $this->assertSame('/usr/bin/php8.4', $this->phpFpm->getPhpExecutablePath());
    }

    #[Test]
    public function itFallsBackToUsrBinPhpWhenNoConfiguredBinaryExists(): void
    {
        $devTools = Mockery::mock(DevTools::class);
        swap(DevTools::class, $devTools);

        $devTools->shouldReceive('getBin')
            ->with('php', ['/usr/local/bin/php'])
            ->once()
            ->andReturn(false);

        $this->config->shouldReceive('get')
            ->with('fallback_binary')
            ->once()
            ->andReturn(null);

        $this->filesystem->shouldReceive('exists')->with('/usr/bin/php')->once()->andReturn(true);

        $this->assertSame('/usr/bin/php', $this->phpFpm->getPhpExecutablePath());
    }

    #[Test]
    public function itFallsBackToUsrBinPhpVersionWhenVersionedLookupFails(): void
    {
        $devTools = Mockery::mock(DevTools::class);
        swap(DevTools::class, $devTools);

        $devTools->shouldReceive('getBin')
            ->with('php8.2', ['/usr/local/bin/php'])
            ->once()
            ->andReturn(false);

        $this->filesystem->shouldReceive('exists')
            ->with('/usr/bin/php8.2')
            ->once()
            ->andReturn(true);

        $this->assertSame('/usr/bin/php8.2', $this->phpFpm->getPhpExecutablePath('8.2'));
    }

    #[Test]
    #[DataProvider('executableVersionProvider')]
    public function itWillGetFpmSocketFile(string $version, string $expectedSocketFile): void
    {
        $socketFile = $this->phpFpm->fpmSocketFile($version);

        $this->assertSame(VALET_HOME_PATH . '/' . $expectedSocketFile, $socketFile);
    }

    public static function versionDataProvider(): array
    {
        return [
            [
                '8.2',
            ],
            [
                '8.3',
            ]
        ];
    }

    #[Test]
    #[DataProvider('versionDataProvider')]
    public function itWillValidateVersion(string $version): void
    {
        $isValid = $this->phpFpm->validateVersion($version);

        $this->assertTrue($isValid);
    }

    public static function deprecatedVersionDataProvider(): array
    {
        return [
            [
                '7.0',
            ],
            [
                '7.1',
            ],
            [
                '7.2',
            ],
            [
                '7.3',
            ],
            [
                '7.4',
            ],
            [
                '8.0',
            ],
            [
                '8.1',
            ],
        ];
    }

    #[Test]
    #[DataProvider('deprecatedVersionDataProvider')]
    public function itWillValidateDeprecatedVersion(string $version): void
    {
        $isValid = $this->phpFpm->validateVersion($version);

        $this->assertFalse($isValid);
    }
}
