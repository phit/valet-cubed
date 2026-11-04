<?php

namespace Unit;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\SiteProxy;
use Valet\SiteSecure;
use Valet\Tests\TestCase;

class SiteProxyTest extends TestCase
{
    private Configuration&MockInterface $config;
    private Filesystem&MockInterface $filesystem;
    private SiteSecure&MockInterface $siteSecure;
    private SiteProxy $siteProxy;

    public function setUp(): void
    {
        parent::setUp();

        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);
        $this->siteSecure = Mockery::mock(SiteSecure::class);

        $this->siteProxy = new SiteProxy(
            $this->filesystem,
            $this->config,
            $this->siteSecure
        );
    }

    #[Test]
    public function itWillCreateProxy(): void
    {
        $this->config
            ->shouldReceive('parseDomain')
            ->once()
            ->with('site')
            ->andReturn('site.test');

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_ROOT_PATH . '/cli/stubs/secure.proxy.valet.conf')
            ->andReturn('VALET_PROXY_HOST');

        $this->siteSecure
            ->shouldReceive('secure')
            ->once()
            ->with('site.test', 'http://localhost:8000');

        $this->siteProxy->proxyCreate('site', 'http://localhost:8000', true);
    }

    #[Test]
    public function itWillDeleteAProxyThatWasNeverSecured(): void
    {
        // unsecure() only removes configurations that have a certificate.
        $this->config->shouldReceive('parseDomain')->with('app')->andReturn('app.test');
        $this->filesystem->shouldReceive('exists')->with(VALET_HOME_PATH . '/Nginx/app.test')->andReturnTrue();
        $this->filesystem->shouldReceive('get')->with(VALET_HOME_PATH . '/Nginx/app.test')
            ->andReturn('proxy_pass http://localhost:3000;');
        $this->siteSecure->shouldReceive('unsecure')->once()->with('app.test');
        $this->filesystem->shouldReceive('unlink')->once()->with(VALET_HOME_PATH . '/Nginx/app.test');

        $this->assertTrue($this->siteProxy->proxyDelete('app'));
    }

    #[Test]
    public function itWillNotDeleteASiteThatIsNotAProxy(): void
    {
        $this->config->shouldReceive('parseDomain')->with('blog.test')->andReturn('blog.test');
        $this->filesystem->shouldReceive('exists')->with(VALET_HOME_PATH . '/Nginx/blog.test')->andReturnTrue();
        $this->filesystem->shouldReceive('get')->with(VALET_HOME_PATH . '/Nginx/blog.test')
            ->andReturn('fastcgi_pass unix:/run/php.sock;');
        $this->siteSecure->shouldNotReceive('unsecure');
        $this->filesystem->shouldNotReceive('unlink');

        $this->assertFalse($this->siteProxy->proxyDelete('blog.test'));
    }

    #[Test]
    public function itWillLoadProxies(): void
    {
        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('domain')
            ->andReturn('test');

        $this->siteSecure
            ->shouldReceive('secured')
            ->once()
            ->withNoArgs()
            ->andReturn(collect([
                'site1.test'
            ]));

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx')
            ->andReturn([
                'site1.test'
            ]);

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site1.test')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site1.test')
            ->andReturn('proxy_pass http://localhost:8025;');

        $response = $this->siteProxy->proxies();

        $this->assertSame([
            'site1.test' => [
                'url' => 'https://site1.test',
                'secured' => '✓',
                'path' => 'http://localhost:8025',
            ],
        ], $response->all());
    }
}
