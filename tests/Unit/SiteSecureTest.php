<?php

namespace Unit;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Filesystem;
use Valet\PhpFpm;
use Valet\RunAs;
use Valet\SiteSecure;
use Valet\Tests\TestCase;

use function Valet\resolve;
use function Valet\swap;
use function Valet\user;

class SiteSecureTest extends TestCase
{
    private Filesystem&MockInterface $filesystem;
    private CommandLine&MockInterface $commandLine;
    private Configuration&MockInterface $config;
    private PackageManager $pm;
    private SiteSecure $siteSecure;

    public function testRejectsSiteNamesContainingPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->siteSecure->secure('../site.test');
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->config = Mockery::mock(Configuration::class);
        $this->pm = resolve(PackageManager::class);
        $this->siteSecure = new SiteSecure(
            $this->filesystem,
            $this->commandLine,
            $this->config,
            $this->pm
        );
    }

    #[Test]
    public function itWillTrustAnExistingCaOnlyOncePerRun(): void
    {
        // Renewing every secured site used to trust the same CA again for each.
        $this->filesystem->shouldReceive('exists')->with(VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.key')->andReturnTrue();
        $this->filesystem->shouldReceive('exists')->with(VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem')->andReturnTrue();
        $this->filesystem->shouldReceive('copy')->once();
        $this->commandLine->shouldReceive('run')->once()->with($this->pm->getCaUpdateCommand(), null, RunAs::Root);
        $this->commandLine->shouldReceive('runAsUser')->times(3)->with(Mockery::pattern('/^certutil /'));

        foreach (['blog.test', 'shop.test', 'api.test'] as $site) {
            (fn () => $this->createCa(365))->call($this->siteSecure);
        }
    }

    #[Test]
    public function itWillSecureNewDomain(): void
    {
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);

        $caCertificatesPath = $this->pm->getCaCertificatesPath();

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site.test')
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH . '/CA', user());

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates', user());

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem')
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('exists')
            ->twice()
            ->with(VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.key')
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('remove')
            ->once()
            ->with($caCertificatesPath . '/ValetLinuxCASelfSigned.pem.crt')
            ->andReturnFalse();

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->with($this->pm->getCaUpdateCommand(), null, RunAs::Root);

        $caExpireInDate = (new \DateTime())->diff(new \DateTime("+20 years"));
        $expiryInDays = (int)$caExpireInDate->format('%a'); // 20 years in days
        $subject = sprintf(
            '/C=/ST=/O=%s/localityName=/commonName=%s/organizationalUnitName=Developers/emailAddress=%s/',
            'Valet Linux CA Self Signed Organization',
            'Valet Linux CA Self Signed CN',
            'certificate@valet.linux',
        );
        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(sprintf(
                'openssl req -new -newkey rsa:2048 -days %s -nodes -x509 -subj "%s" -keyout "%s" -out "%s"',
                $expiryInDays,
                $subject,
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.key',
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem'
            ));

        $this->filesystem
            ->shouldReceive('copy')
            ->once()
            ->with(
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem',
                $caCertificatesPath . '/ValetLinuxCASelfSigned.pem.crt'
            );

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->with($this->pm->getCaUpdateCommand(), null, RunAs::Root);

        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(sprintf(
                'certutil -d sql:$HOME/.pki/nssdb -A -t TC -n "%s" -i "%s"',
                'Valet Linux CA Self Signed Organization',
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem'
            ));

        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(sprintf(
                'certutil -d $HOME/.mozilla/firefox/*.default -A -t TC -n "%s" -i "%s"',
                'Valet Linux CA Self Signed Organization',
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem'
            ));

        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(sprintf(
                'certutil -d $HOME/snap/firefox/common/.mozilla/firefox/*.default -A -t TC -n "%s" -i "%s"',
                'Valet Linux CA Self Signed Organization',
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem'
            ));

        $certificateExpireInDate = (new \DateTime())->diff(new \DateTime("+1 year"));
        $certificateExpireInDays = (int)$certificateExpireInDate->format('%a'); // 20 years in days

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_ROOT_PATH . '/cli/stubs/openssl.conf')
            ->andReturn('SSL conf');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.conf', 'SSL conf');

        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(\sprintf(
                'openssl genrsa -out %s 2048',
                VALET_HOME_PATH . '/Certificates/site.test.key'
            ));

        $subject = sprintf(
            '/C=/ST=/O=/localityName=/commonName=%s/organizationalUnitName=/emailAddress=%s/',
            'site.test',
            'certificate@valet.linux',
        );
        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(\sprintf(
                'openssl req -new -key %s -out %s -subj "%s" -config %s',
                VALET_HOME_PATH . '/Certificates/site.test.key',
                VALET_HOME_PATH . '/Certificates/site.test.csr',
                $subject,
                VALET_HOME_PATH . '/Certificates/site.test.conf'
            ));

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.srl')
            ->andReturnFalse();

        $caSrlParam = '-CAserial "' . VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.srl" -CAcreateserial';

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->with(sprintf(
                'openssl x509 -req -sha256 -days %s -CA "%s" -CAkey "%s" %s -in %s -out %s -extensions v3_req -extfile %s',
                $certificateExpireInDays,
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem',
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.key',
                $caSrlParam,
                VALET_HOME_PATH . '/Certificates/site.test.csr',
                VALET_HOME_PATH . '/Certificates/site.test.crt',
                VALET_HOME_PATH . '/Certificates/site.test.conf'
            ));

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_ROOT_PATH . '/cli/stubs/secure.valet.conf')
            ->andReturn('Nginx Conf');

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('port', 80)
            ->andReturn(80);

        $this->config
            ->shouldReceive('get')
            ->twice()
            ->with('https_port', 443)
            ->andReturn(443);

        $phpFpm->shouldReceive('getCurrentVersion')
            ->once()
            ->withNoArgs()
            ->andReturn('8.3');

        $phpFpm->shouldReceive('fpmSocketFile')
            ->once()
            ->with('8.3')
            ->andReturn(VALET_HOME_PATH . '/' . 'valet83.sock');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site.test', 'Nginx Conf');

        $this->siteSecure->secure('site.test');
    }

    #[Test]
    public function itWillUnsecureSite(): void
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.crt')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site.test');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.conf');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.key');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.csr');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.crt');

        $this->siteSecure->unsecure('site.test');
    }

    #[Test]
    public function itWillListSecuredSites(): void
    {
        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates')
            ->andReturn([
                'site.test.crt',
                'site.test.csr',
                'site.test.key',
                'site.test.conf',
                'site2.test.crt',
                'site2.test.csr',
                'site2.test.key',
                'site2.test.conf',
            ]);

        $sites = $this->siteSecure->secured();

        $this->assertSame(['site.test', 'site2.test'], $sites->toArray());
    }

    #[Test]
    public function itWillRebuildSecuredSitesFromTheCurrentStubs(): void
    {
        $nginx = VALET_HOME_PATH . '/Nginx';
        $certificates = VALET_HOME_PATH . '/Certificates';
        // Releases before 2.0 wrote no stub marker and used a socket that no longer exists.
        $legacySite = "server {\n    listen 127.0.0.1:443 ssl http2;\n        fastcgi_pass unix:/home/user/.config/valet/valet.sock;\n}\n";
        $proxy = "# valet stub: secure.proxy.valet.conf\nserver {\n    listen 127.0.0.1:443 ssl http2;\n"
            . "    location / {\n        proxy_pass http://127.0.0.1:8099;\n    }\n}\n";
        $stub = VALET_ROOT_PATH . '/cli/stubs/secure.proxy.valet.conf';

        $this->filesystem->shouldReceive('exists')->with($certificates)->andReturnTrue();
        $this->filesystem->shouldReceive('scandir')->with($certificates)->andReturn(['site.test.crt', 'api.test.crt']);
        $this->filesystem->shouldReceive('exists')->with($nginx . '/site.test')->andReturnTrue();
        $this->filesystem->shouldReceive('get')->with($nginx . '/site.test')->andReturn($legacySite);
        $this->filesystem->shouldReceive('exists')->with($nginx . '/api.test')->andReturnTrue();
        $this->filesystem->shouldReceive('get')->with($nginx . '/api.test')->andReturn($proxy);
        $this->filesystem->shouldReceive('get')->with($stub)->andReturn(file_get_contents($stub));

        /** @var SiteSecure&MockInterface $siteSecure */
        $siteSecure = Mockery::mock(SiteSecure::class . '[unsecure,secure]', [
            $this->filesystem, $this->commandLine, $this->config, $this->pm,
        ]);
        $siteSecure->shouldReceive('unsecure')->with('site.test')->once();
        $siteSecure->shouldReceive('unsecure')->with('api.test')->once();
        // No stub: secure() builds the site from the default stub.
        $siteSecure->shouldReceive('secure')->with('site.test', null)->once();
        $siteSecure->shouldReceive('secure')->with('api.test', Mockery::on(
            fn (string $conf) => str_starts_with($conf, '# valet stub: secure.proxy.valet.conf')
                && !str_contains($conf, 'listen 127.0.0.1')
                && str_contains($conf, 'proxy_pass http://127.0.0.1:8099;')
        ))->once();

        $siteSecure->reSecureForNewDomain('test', 'test');
    }

    #[Test]
    public function itWillRebuildUnsecuredProxiesFromTheCurrentStub(): void
    {
        $nginx = VALET_HOME_PATH . '/Nginx';
        // Releases before 2.0 bound proxies to 127.0.0.1, taking over every site.
        $legacyProxy = "# valet stub: proxy.valet.conf\n\nserver {\n    listen 127.0.0.1:80;\n"
            . "    error_page 404 \"/old/valet/server.php\";\n    location / {\n        proxy_pass http://127.0.0.1:8099;\n    }\n}\n";
        $stub = VALET_ROOT_PATH . '/cli/stubs/proxy.valet.conf';

        $this->filesystem->shouldReceive('exists')->with($nginx)->andReturnTrue();
        $this->filesystem->shouldReceive('scandir')->with(VALET_HOME_PATH . '/Certificates')->andReturn(['secure.test.crt']);
        $this->filesystem->shouldReceive('scandir')->with($nginx)->andReturn(['.keep', 'api.test', 'secure.test', 'isolated.test']);
        $this->filesystem->shouldReceive('exists')->with($nginx . '/api.test')->andReturnTrue();
        $this->filesystem->shouldReceive('get')->with($nginx . '/api.test')->andReturn($legacyProxy);
        $this->filesystem->shouldReceive('exists')->with($nginx . '/isolated.test')->andReturnTrue();
        $this->filesystem->shouldReceive('get')->with($nginx . '/isolated.test')->andReturn("# custom site\n");
        $this->filesystem->shouldReceive('get')->with($stub)->andReturn(file_get_contents($stub));
        $this->filesystem->shouldReceive('ensureDirExists');
        $this->config->shouldReceive('get')->with('port', 80)->andReturn(80);
        $this->config->shouldReceive('get')->with('https_port', 443)->andReturn(443);

        $this->filesystem->shouldReceive('putAsUser')->once()->with($nginx . '/api.test', Mockery::on(
            fn (string $conf) => !str_contains($conf, 'listen 127.0.0.1')
                && str_contains($conf, 'proxy_pass http://127.0.0.1:8099;')
                && !str_contains($conf, '/old/valet/server.php')
                && str_contains($conf, 'server_name api.test ')
        ));

        $this->siteSecure->rebuildUnsecuredProxies();
    }

    #[Test]
    public function itWillRegenerateSecuredSites(): void
    {
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);

        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates')
            ->andReturn([
                'site.test.crt',
                'site.test.csr',
                'site.test.key',
                'site.test.conf',
                'site2.test.crt',
                'site2.test.csr',
                'site2.test.key',
                'site2.test.conf',
            ]);
        foreach (['site.test', 'site2.test'] as $site) {

            $this->filesystem
                ->shouldReceive('get')
                ->with(VALET_ROOT_PATH . '/cli/stubs/secure.valet.conf')
                ->once()
                ->andReturn('Nginx Conf');

            $this->config
                ->shouldReceive('get')
                ->once()
                ->with('port', 80)
                ->andReturn(80);

            $this->config
                ->shouldReceive('get')
                ->twice()
                ->with('https_port', 443)
                ->andReturn(443);

            $phpFpm->shouldReceive('getCurrentVersion')
                ->once()
                ->withNoArgs()
                ->andReturn('8.3');

            $phpFpm->shouldReceive('fpmSocketFile')
                ->once()
                ->with('8.3')
                ->andReturn(VALET_HOME_PATH . '/' . 'valet83.sock');

            $this->filesystem
                ->shouldReceive('putAsUser')
                ->once()
                ->with(VALET_HOME_PATH . '/Nginx/' . $site, 'Nginx Conf');
        }

        $this->siteSecure->regenerateSecuredSitesConfig();

        $this->assertTrue(true);
    }
}
