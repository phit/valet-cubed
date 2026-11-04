<?php

namespace Valet\Tests\Drivers;

use Valet\Drivers\BasicValetDriver;
use Valet\Drivers\Specific\BedrockValetDriver;
use Valet\Drivers\ValetDriver;

class ValetDriverTest extends BaseDriverTestCase
{
    public function test_it_gets_drivers_in_given_path(): void
    {
        $output = ValetDriver::driversIn(__DIR__.'/../files/Drivers');

        $this->assertEquals(2, count($output));
        $this->assertContains('Test1ValetDriver', $output);
        $this->assertContains('Test2ValetDriver', $output);
    }

    public function test_it_assigns_drivers_to_given_project(): void
    {
        $assignedDriver = ValetDriver::assign($this->projectDir('bedrock'), 'my-site', '/');

        $this->assertEquals(BedrockValetDriver::class, get_class($assignedDriver));
    }

    public function test_it_prioritizes_non_basic_matches(): void
    {
        $assignedDriver = ValetDriver::assign($this->projectDir('laravel'), 'my-site', '/');

        $this->assertNotEquals('Valet\Drivers\BasicWithPublicValetDriver', get_class($assignedDriver));
        $this->assertNotEquals('Valet\Drivers\BasicValetDriver', get_class($assignedDriver));
    }

    public function test_it_prioritizes_statamic(): void
    {
        $assignedDriver = ValetDriver::assign($this->projectDir('statamic'), 'my-site', '/');
        $this->assertEquals('Valet\Drivers\Specific\StatamicValetDriver', get_class($assignedDriver));

        $assignedDriver = ValetDriver::assign($this->projectDir('laravel'), 'my-site', '/');
        $this->assertEquals('Valet\Drivers\LaravelValetDriver', get_class($assignedDriver));
    }

    public function test_it_checks_composer_dependencies(): void
    {
        $driver = new BasicValetDriver();
        $this->assertTrue($driver->composerRequires(__DIR__.'/../files/sites/has-composer', 'tightenco/collect'));
        $this->assertFalse($driver->composerRequires(__DIR__.'/../files/sites/has-composer', 'tightenco/ziggy'));
    }
}
