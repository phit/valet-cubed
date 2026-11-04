<?php

namespace Valet\Tests\Drivers;

use Valet\Drivers\Specific\Shopware5ValetDriver;

class Shopware5ValetDriverTest extends BaseDriverTestCase
{
    public function test_it_serves_shopware5_projects()
    {
        $driver = new Shopware5ValetDriver();

        $this->assertTrue($driver->serves($this->projectDir('shopware5'), 'my-site', '/'));
    }

    public function test_it_doesnt_serve_non_shopware5_projects()
    {
        $driver = new Shopware5ValetDriver();

        $this->assertFalse($driver->serves($this->projectDir('public-with-index-non-laravel'), 'my-site', '/'));
        $this->assertFalse($driver->serves($this->projectDir('symfony'), 'my-site', '/'));
    }

    public function test_it_serves_files_but_not_directories_as_static()
    {
        $driver = new Shopware5ValetDriver();
        $path = $this->projectDir('shopware5');

        $this->assertSame($path.'/media/image/logo.png', $driver->isStaticFile($path, 'my-site', '/media/image/logo.png'));
        $this->assertFalse($driver->isStaticFile($path, 'my-site', '/media/image'));
        $this->assertFalse($driver->isStaticFile($path, 'my-site', '/detail/index/sArticle/1'));
    }

    public function test_it_gets_the_shop_front_controller()
    {
        $driver = new Shopware5ValetDriver();
        $path = $this->projectDir('shopware5');

        $this->assertSame($path.'/shopware.php', $driver->frontControllerPath($path, 'my-site', '/'));
        $this->assertSame($path.'/shopware.php', $driver->frontControllerPath($path, 'my-site', '/backend/'));
    }

    public function test_it_routes_the_installer_and_updater_to_their_own_front_controllers()
    {
        $driver = new Shopware5ValetDriver();
        $path = $this->projectDir('shopware5');

        $this->assertSame($path.'/recovery/install/index.php', $driver->frontControllerPath($path, 'my-site', '/recovery/install/'));
        $this->assertSame('/recovery/install/index.php', $_SERVER['SCRIPT_NAME']);
        $this->assertSame($path, $_SERVER['DOCUMENT_ROOT']);

        $this->assertSame($path.'/recovery/update/index.php', $driver->frontControllerPath($path, 'my-site', '/recovery/update/index.php/checks'));
        $this->assertSame($path.'/recovery/update/index.php', $_SERVER['SCRIPT_FILENAME']);

        // Only the recovery apps themselves, not shop URLs that mention them.
        $this->assertSame($path.'/shopware.php', $driver->frontControllerPath($path, 'my-site', '/search?q=recovery/install'));
    }
}
