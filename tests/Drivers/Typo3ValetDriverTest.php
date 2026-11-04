<?php

namespace Valet\Tests\Drivers;

use Valet\Drivers\Specific\Typo3ValetDriver;

class Typo3ValetDriverTest extends BaseDriverTestCase
{
    public function test_it_serves_typo3_projects(): void
    {
        $driver = new Typo3ValetDriver();

        $this->assertTrue($driver->serves($this->projectDir('typo3'), 'my-site', '/'));
    }

    public function test_it_doesnt_serve_non_typo3_projects(): void
    {
        $driver = new Typo3ValetDriver();

        $this->assertFalse($driver->serves($this->projectDir('public-with-index-non-laravel'), 'my-site', '/'));
    }

    public function test_it_gets_front_controller(): void
    {
        $driver = new Typo3ValetDriver();

        $projectPath = $this->projectDir('typo3');
        $_SERVER['HTTP_HOST'] = 'my-site.test';
        $driver->beforeLoading($projectPath, 'my-site', '/');
        $this->assertEquals($projectPath.'/web/index.php', $driver->frontControllerPath($projectPath, 'my-site', '/'));
        $this->assertSame('my-site.test', $_SERVER['SERVER_NAME']);
    }
}
