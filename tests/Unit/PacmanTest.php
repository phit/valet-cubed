<?php

namespace Valet\Tests\Unit;

use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Valet\CommandLine;
use Valet\PackageManagers\Pacman;
use Valet\Tests\TestCase;

class PacmanTest extends TestCase
{
    private function pacman(string $officialVersion, array $commands = []): Pacman
    {
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')
            ->with('pacman -Si php 2>/dev/null || pacman -Qi php 2>/dev/null')
            ->andReturn("Repository      : extra\nName            : php\nVersion         : {$officialVersion}-1\n");
        foreach ($commands as $command => $output) {
            $cli->shouldReceive('run')->with($command)->andReturn($output);
        }

        return new Pacman($cli);
    }

    #[Test]
    public function itWillUseTheOfficialPackagesForTheRepositoryVersion(): void
    {
        $pacman = $this->pacman('8.5.12');

        $this->assertSame('php-fpm', $pacman->getPhpFpmName('8.5'));
        $this->assertSame('php-fpm', $pacman->getPhpFpmServiceName('8.5'));
        $this->assertSame('php-gd', $pacman->getPhpExtensionName('8.5', 'gd'));
        $this->assertSame('php-pgsql', $pacman->getPhpExtensionName('8.5', 'pgsql'));
        $this->assertSame('php', $pacman->getPhpExtensionName('8.5', 'intl'));
        $this->assertSame('php', $pacman->getPhpExtensionName('8.5', 'mysql'));
    }

    #[Test]
    public function itWillUseAurNamesForOtherVersions(): void
    {
        $pacman = $this->pacman('8.5.12');

        $this->assertSame('php84-fpm', $pacman->getPhpFpmName('8.4'));
        $this->assertSame('php84-gd', $pacman->getPhpExtensionName('8.4', 'gd'));
    }

    #[Test]
    public function itWillEnableShippedModulesThatAreNotLoaded(): void
    {
        $written = null;
        $pacman = $this->pacman('8.5.12', [
            'php -m 2>/dev/null' => "[PHP Modules]\nCore\nmbstring\nmysqli\n",
            "ls '/usr/lib/php/modules' 2>/dev/null" => "curl.so\ngd.so\nintl.so\nmysqli.so\npdo_mysql.so\nzip.so\n",
        ]);
        $pacman->cli->shouldReceive('run')
            ->with(Mockery::pattern('/^printf /'))
            ->once()
            ->andReturnUsing(function (string $command) use (&$written) {
                $written = $command;

                return '';
            });

        $pacman->enablePhpExtensions('8.5', ['cli', 'mysql', 'gd', 'zip', 'curl', 'mbstring', 'pgsql', 'intl']);

        $this->assertSame(
            "printf '%s\\n' '; Enabled by Valet Cubed' 'extension=pdo_mysql' 'extension=gd' 'extension=zip' "
            . "'extension=curl' 'extension=intl' > '/etc/php/conf.d/valet.ini'",
            $written
        );
    }
}
