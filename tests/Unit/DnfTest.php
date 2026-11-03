<?php

namespace Valet\Tests\Unit;

use Mockery;
use Valet\CommandLine;
use Valet\PackageManagers\Dnf;
use Valet\Tests\TestCase;

class DnfTest extends TestCase
{
    public function testPrefersInstalledRemiPackagesAndUsesMatchingExtensions(): void
    {
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')->once()->with("rpm -q 'php83-php-fpm' >/dev/null 2>&1 && echo ok || echo no")->andReturn('ok');
        $manager = new Dnf($cli);
        $this->assertSame('php83-php-fpm', $manager->getPhpFpmName('8.3'));
        $this->assertSame('php83-php-mysql', $manager->getPhpExtensionName('8.3', 'mysql'));
    }

    public function testUsesInstalledNativeVersionWithoutProbingRepositories(): void
    {
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')->once()->with("rpm -q 'php83-php-fpm' >/dev/null 2>&1 && echo ok || echo no")->andReturn('no');
        $cli->shouldReceive('run')->once()->with("rpm -q --queryformat '%{VERSION}' php-fpm 2>/dev/null || true")->andReturn('8.3.12');
        $manager = new Dnf($cli);
        $this->assertSame('php-fpm', $manager->getPhpFpmName('8.3'));
        $this->assertSame('php-mysql', $manager->getPhpExtensionName('8.3', 'mysql'));
    }

    public function testInstalledUsesRpmDatabaseAndQuotesThePackageName(): void
    {
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')->once()
            ->with("rpm -q 'php-fpm' >/dev/null 2>&1 && echo ok || echo no")
            ->andReturn("ok\n");
        $this->assertTrue((new Dnf($cli))->installed('php-fpm'));
    }
}
