<?php

namespace Valet\Tests\Unit;

use Mockery;
use Valet\CommandLine;
use Valet\PackageManagers\Dnf;
use Valet\Tests\TestCase;

class DnfTest extends TestCase
{
    public function testInstalledUsesRpmDatabaseAndQuotesThePackageName(): void
    {
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')->once()
            ->with("rpm -q 'php-fpm' >/dev/null 2>&1 && echo ok || echo no")
            ->andReturn("ok\n");
        $this->assertTrue((new Dnf($cli))->installed('php-fpm'));
    }
}
