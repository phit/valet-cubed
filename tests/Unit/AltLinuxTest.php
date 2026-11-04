<?php

namespace Valet\Tests\Unit;

use Mockery;
use Valet\CommandLine;
use Valet\Contracts\ServiceManager;
use Valet\PackageManagers\AltLinux;
use Valet\Tests\TestCase;

class AltLinuxTest extends TestCase
{
    public function testAltLinuxUsesItsOwnPackageAndServiceNames(): void
    {
        $manager = new AltLinux(Mockery::mock(CommandLine::class), Mockery::mock(ServiceManager::class));
        $this->assertSame('php8.3-fpm-fcgi', $manager->getPhpFpmName('8.3'));
        $this->assertSame('php8.3-fpm', $manager->getPhpFpmServiceName('8.3'));
        $this->assertSame('php8.3-pdo_mysql', $manager->getPhpExtensionName('8.3', 'mysql'));
        $this->assertSame('php8.3-pdo_pgsql', $manager->getPhpExtensionName('8.3', 'pgsql'));
        $this->assertSame('php8.3-xmlreader', $manager->getPhpExtensionName('8.3', 'xml'));
        $this->assertSame('php8.3', $manager->getPhpExtensionName('8.3', 'cli'));
        $this->assertSame('php8.3-intl', $manager->getPhpExtensionName('8.3', 'intl'));
        $this->assertSame('/etc/pki/ca-trust/source/anchors', $manager->getCaCertificatesPath());
        $this->assertSame('update-ca-trust', $manager->getCaUpdateCommand());
    }

    public function testAltLinuxDetectionAcceptsQuotedOsReleaseId(): void
    {
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')->once()->with('which apt-get', Mockery::type('callable'))->andReturn('/usr/bin/apt-get');
        $cli->shouldReceive('run')->once()->with('cat /etc/os-release 2>/dev/null', Mockery::type('callable'))->andReturn("ID=\"altlinux\"\n");
        $manager = new AltLinux($cli, Mockery::mock(ServiceManager::class));
        $this->assertTrue($manager->isAvailable());
    }
}
