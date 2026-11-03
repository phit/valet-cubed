<?php

namespace Valet\Tests\Unit;

use DomainException;
use Mockery;
use Valet\CommandLine;
use Valet\Filesystem;
use Valet\ServiceManagers\LinuxService;
use Valet\ServiceManagers\Systemd;
use Valet\Tests\TestCase;

class ServiceManagerSecurityTest extends TestCase
{
    /** @dataProvider invalidServiceNames */
    public function testRejectsInvalidServiceNamesBeforeRunningCommands(string $manager, string $service): void
    {
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldNotReceive('run');
        $cli->shouldNotReceive('quietly');
        $instance = new $manager($cli, Mockery::mock(Filesystem::class));

        $this->expectException(DomainException::class);
        $instance->start($service);
    }

    public function invalidServiceNames(): array
    {
        $cases = [];
        foreach ([Systemd::class, LinuxService::class] as $manager) {
            foreach (['nginx; touch /tmp/valet-injection', '-option', '../nginx', '..'] as $service) {
                $cases[] = [$manager, $service];
            }
        }

        return $cases;
    }
}
