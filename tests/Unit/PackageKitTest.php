<?php

namespace Valet\Tests\Unit;

use Mockery;
use Valet\CommandLine;
use Valet\PackageManagers\PackageKit;
use Valet\Tests\TestCase;

class PackageKitTest extends TestCase
{
    private function packageKit(string $osRelease): PackageKit
    {
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')->with('cat /etc/os-release 2>/dev/null')->once()->andReturn($osRelease);

        return new PackageKit($cli);
    }

    public function testUsesOpenSuseNames(): void
    {
        $manager = $this->packageKit("NAME=\"openSUSE Leap\"\nID=\"opensuse-leap\"\nID_LIKE=\"suse opensuse\"\n");

        $this->assertSame('php8-fpm', $manager->getPhpFpmName('8.4'));
        $this->assertSame('php-fpm', $manager->getPhpFpmServiceName('8.4'));
        $this->assertSame('php8-gd', $manager->getPhpExtensionName('8.4', 'gd'));
        $this->assertSame('php8-dom', $manager->getPhpExtensionName('8.4', 'xml'));
        $this->assertSame('mariadb', $manager->packageName('mysql'));
        $this->assertSame('redis', $manager->packageName('redis'));
        $this->assertSame('/etc/pki/trust/anchors', $manager->getCaCertificatesPath());
        $this->assertSame('update-ca-certificates', $manager->getCaUpdateCommand());
    }

    public function testKeepsGenericNamesElsewhere(): void
    {
        $manager = $this->packageKit("ID=fedora\n");

        $this->assertSame('php8.4-fpm', $manager->getPhpFpmName('8.4'));
        $this->assertSame('php8.4-gd', $manager->getPhpExtensionName('8.4', 'gd'));
        $this->assertSame('mysql-server', $manager->packageName('mysql'));
        $this->assertSame('update-ca-trust', $manager->getCaUpdateCommand());
    }
}
