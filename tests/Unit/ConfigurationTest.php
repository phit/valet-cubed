<?php

namespace Valet\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Tests\TestCase;

use function Valet\user;

class ConfigurationTest extends TestCase
{
    private Filesystem|MockObject $filesystem;
    private Configuration $configuration;

    public function setUp(): void
    {
        parent::setUp();

        $this->filesystem = \Mockery::mock(Filesystem::class);
        $this->filesystem->shouldReceive('exists')->with(VALET_HOME_PATH . '/config.json')->andReturnTrue()->byDefault();
        $this->configuration = new Configuration($this->filesystem);
    }

    #[Test]
    public function itWillReturnDefaultsWithoutAConfigurationFile(): void
    {
        $this->filesystem->shouldReceive('exists')->with(VALET_HOME_PATH . '/config.json')->andReturnFalse();
        $this->filesystem->shouldNotReceive('get');

        $this->assertSame('8.4', $this->configuration->get('php_version', '8.4'));
    }

    #[Test]
    public function itWillInstallConfigurationSuccessfully(): void
    {
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH, user());
        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with(VALET_HOME_PATH.'/Drivers')
            ->andReturn(false);
        $this->filesystem
            ->shouldReceive('mkdirAsUser')
            ->once()
            ->with(VALET_HOME_PATH.'/Drivers')
            ->andReturn(false);
        $this->filesystem
            ->shouldReceive('get')
            ->with(VALET_ROOT_PATH.'/cli/stubs/SampleValetDriver.php')
            ->once()
            ->andReturn('Sample Valet Driver Content');
        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(VALET_HOME_PATH.'/Drivers/SampleValetDriver.php', 'Sample Valet Driver Content');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Sites', user());
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Extensions', user());
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Log', user());
        $this->filesystem
            ->shouldReceive('touch')
            ->once()
            ->with(VALET_HOME_PATH.'/Log/nginx-error.log');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Certificates', user());
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
        ->andReturn(false);
        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(
                VALET_HOME_PATH.'/config.json',
                json_encode(
                    $this->defaultConfig(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );
        $this->filesystem
            ->shouldReceive('chown')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json', user())
        ->andReturn(false);

        $this->configuration->install();
    }

    #[Test]
    public function itWillNotOverrideWhenAlreadyInstalled(): void
    {
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH, user());
        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with(VALET_HOME_PATH.'/Drivers')
            ->andReturn(true);
        $this->filesystem
            ->shouldNotReceive('mkdirAsUser');
        $this->filesystem
            ->shouldNotReceive('get')
            ->with(VALET_ROOT_PATH.'/cli/stubs/SampleValetDriver.php');
        $this->filesystem
            ->shouldNotReceive('putAsUser')
            ->with(VALET_HOME_PATH.'/Drivers/SampleValetDriver.php', 'Sample Valet Driver Content');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Sites', user());
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Extensions', user());
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Log', user());
        $this->filesystem
            ->shouldReceive('touch')
            ->once()
            ->with(VALET_HOME_PATH.'/Log/nginx-error.log');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Certificates', user());
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
        ->andReturn(true);
        $this->filesystem
            ->shouldNotReceive('putAsUser')
            ->with(
                VALET_HOME_PATH.'/config.json',
                json_encode(
                    $this->defaultConfig(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );
        $this->filesystem
            ->shouldReceive('chown')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json', user())
        ->andReturn(false);

        $this->configuration->install();
    }

    #[Test]
    public function itPreservesConfigurationOnUninstall(): void
    {
        $this->filesystem
            ->shouldNotReceive('remove')
            ->with(VALET_HOME_PATH);

        $this->configuration->uninstall();
    }

    #[Test]
    public function itWillAddPathToConfig(): void
    {
        $defaultConfig = $this->defaultConfig();
        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode($defaultConfig));

        $defaultConfig['paths'] = ['new_path'];
        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with(
                VALET_HOME_PATH.'/config.json',
                json_encode(
                    $defaultConfig,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );

        $this->configuration->addPath('new_path');
    }

    #[Test]
    public function itWillRemovePathFromConfig(): void
    {
        $defaultConfig = $this->defaultConfig();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode([...$defaultConfig, 'paths' => ['new_path']]));

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with(
                VALET_HOME_PATH.'/config.json',
                json_encode(
                    $defaultConfig,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );

        $this->configuration->removePath('new_path');
    }

    #[Test]
    public function itWillRemoveBrokenPathsFromConfig(): void
    {
        $defaultConfig = $this->defaultConfig();

        $this->filesystem
            ->shouldReceive('exists')
            ->twice()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode([...$defaultConfig, 'paths' => ['new_path']]));

        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with('new_path')
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with(
                VALET_HOME_PATH.'/config.json',
                json_encode(
                    $defaultConfig,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );

        $this->configuration->prune();
    }

    public static function configDataProvider(): array
    {
        return [
            [
                'domain',
                null,
                'test',
            ],
            [
                'paths',
                null,
                [],
            ],
            [
                'port',
                null,
                '80',
            ],
            [
                'mysql',
                [],
                [],
            ]
        ];
    }

    #[Test]
    #[DataProvider('configDataProvider')]
    public function itWillGetValueFromConfig(string $configKey, mixed $defaultValue, mixed $expectedValue): void
    {
        $defaultConfig = $this->defaultConfig();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode($defaultConfig));

        $value = $this->configuration->get($configKey, $defaultValue);

        $this->assertSame($expectedValue, $value);
    }

    public static function updateConfigDataProvider(): array
    {
        return [
            [
                'domain',
                'new_domain',
            ],
            [
                'paths',
                ['test_path', 'new_path'],
            ],
            [
                'mysql',
                ['user' => 'mysql', 'password' => 'password'],
            ]
        ];
    }

    #[Test]
    #[DataProvider('updateConfigDataProvider')]
    public function itWillSetValueForConfig(string $configKey, mixed $updateValue): void
    {
        $defaultConfig = $this->defaultConfig();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode($defaultConfig));

        $updatedConfig = [...$defaultConfig, ...[$configKey => $updateValue]];
        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(
                VALET_HOME_PATH.'/config.json',
                json_encode(
                    $updatedConfig,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );

        $value = $this->configuration->set($configKey, $updateValue);

        $this->assertSame($updatedConfig, $value);
    }

    public static function domainDataProvider(): array
    {
        return [
            [
                'test',
                'test.test',
            ],
            [
                'site.test',
                'site.test',
            ],
            [
                'site.localhost',
                'site.localhost.test',
            ]
        ];
    }

    #[Test]
    #[DataProvider('domainDataProvider')]
    public function itWillParseDomain(string $siteName, string $expectedDomain): void
    {
        $defaultConfig = $this->defaultConfig();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode($defaultConfig));

        $domain = $this->configuration->parseDomain($siteName);

        $this->assertSame($expectedDomain, $domain);
    }

    private function defaultConfig(): array
    {
        return [
            'domain' => 'test',
            'paths' => [],
            'port' => '80',
        ];
    }
}
