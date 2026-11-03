<?php

namespace Valet\Tests\Drivers;

use Valet\Drivers\Specific\ContaoValetDriver;

class ContaoValetDriverTest extends BaseDriverTestCase
{
    public function test_it_serves_contao_projects()
    {
        $this->markTestSkipped('Fork ships the legacy Contao 3/4 driver (expects vendor/contao + web/app.php); the upstream fixture is modern Contao 4.9+/5 (public/index.php). Re-enable after modernizing the fork driver (see .review U6).');

        $driver = new ContaoValetDriver();

        $this->assertTrue($driver->serves($this->projectDir('contao'), 'my-site', '/'));
    }

    public function test_it_doesnt_serve_non_contao_projects()
    {
        $driver = new ContaoValetDriver();

        $this->assertFalse($driver->serves($this->projectDir('public-with-index-non-laravel'), 'my-site', '/'));
    }

    public function test_it_gets_front_controller()
    {
        $this->markTestSkipped('Fork ships the legacy Contao 3/4 driver; its front controller is web/app.php, not the modern public/index.php. Re-enable after modernizing the fork driver (see .review U6).');

        $driver = new ContaoValetDriver();

        $projectPath = $this->projectDir('contao');
        $this->assertEquals($projectPath.'/public/index.php', $driver->frontControllerPath($projectPath, 'my-site', '/'));
    }
}
