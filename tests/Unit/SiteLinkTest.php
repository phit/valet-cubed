<?php

namespace Unit;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\SiteLink;
use Valet\SiteSecure;
use Valet\Tests\TestCase;

use function Valet\user;

class SiteLinkTest extends TestCase
{
    private Configuration&MockInterface $config;
    private Filesystem&MockInterface $filesystem;
    private SiteSecure&MockInterface $siteSecure;
    private SiteLink $siteLink;

    public function setUp(): void
    {
        parent::setUp();

        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);
        $this->siteSecure = Mockery::mock(SiteSecure::class);

        $this->siteLink = new SiteLink(
            $this->filesystem,
            $this->config,
            $this->siteSecure
        );
    }

    #[Test]
    public function itWillLinkSite(): void
    {
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites', user());

        $this->config
            ->shouldReceive('addPath')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites', true);

        $this->filesystem
            ->shouldReceive('symlinkAsUser')
            ->once()
            ->with('/test/home/path', VALET_HOME_PATH . '/Sites/path');

        $host = $this->siteLink->link('/test/home/path', 'path');

        $this->assertEquals(VALET_HOME_PATH . '/Sites/path', $host);
    }

    #[Test]
    public function itWillUnlinkSite(): void
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites/path')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites/path');

        $this->siteLink->unlink('path');

        $this->assertTrue(true);
    }

    #[Test]
    public function itWillNotUnlinkWhenSiteNotLinked(): void
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites/path')
            ->andReturnFalse();

        $this->filesystem
            ->shouldNotReceive('unlink');

        $this->siteLink->unlink('path');

        $this->assertTrue(true);
    }

    #[Test]
    public function itWillLoadLinks(): void
    {
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates', user());

        $this->siteSecure
            ->shouldReceive('secured')
            ->once()
            ->withNoArgs()
            ->andReturn(collect([
                'site1.test'
            ]));

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('domain')
            ->andReturn('test');

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('port', 80)
            ->andReturn(80);

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('https_port', 443)
            ->andReturn(443);

        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites')
            ->andReturn(['path']);

        $this->filesystem
            ->shouldReceive('readLink')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites/path')
            ->andReturn('/test/home/path');

        $links = $this->siteLink->links();

        $this->assertSame([
            'path' => [
                'url' => 'http://path.test',
                'secured' => '✕',
                'path' => '/test/home/path',
            ],
        ], $links->toArray());
    }
}
