<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use DomainException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\SeLinux;
use Valet\Tests\TestCase;

class SeLinuxTest extends TestCase
{
    private const ENFORCING = "SELinux status:                 enabled\nCurrent mode:                   enforcing\n";

    private CommandLine&MockInterface $cli;
    private PackageManager&MockInterface $pm;
    private Configuration&MockInterface $config;
    private SeLinux $seLinux;

    public function setUp(): void
    {
        parent::setUp();
        Writer::fake();
        $this->cli = Mockery::mock(CommandLine::class);
        $this->pm = Mockery::mock(PackageManager::class);
        $this->config = Mockery::mock(Configuration::class);
        $this->seLinux = new SeLinux($this->cli, $this->pm, $this->config);
    }

    #[Test]
    public function itWillDoNothingUnlessEnforcing(): void
    {
        $this->cli->shouldReceive('run')->with('sestatus 2>/dev/null')->once()
            ->andReturn("SELinux status:                 enabled\nCurrent mode:                   permissive\n");
        $this->cli->shouldNotReceive('run')->with(Mockery::pattern('/semanage/'));

        $this->seLinux->install();
        $this->assertTrue(true);
    }

    #[Test]
    public function itWillMakeTheWebServerDomainPermissiveAndRecordIt(): void
    {
        $this->cli->shouldReceive('run')->with('sestatus 2>/dev/null')->andReturn(self::ENFORCING);
        $this->cli->shouldReceive('run')->with('command -v semanage', Mockery::type('callable'))->andReturn('/usr/sbin/semanage');
        $this->cli->shouldReceive('run')->with('semanage permissive -l -n 2>/dev/null')->andReturn('');
        $this->cli->shouldReceive('run')->with('semanage permissive -a httpd_t', Mockery::type('callable'))->once()->andReturn('');
        $this->config->shouldReceive('set')->with('selinux_permissive_httpd', true)->once();

        $this->seLinux->install();
        $this->assertTrue(true);
    }

    #[Test]
    public function itWillNotClaimAnExistingPermissiveDomain(): void
    {
        $this->cli->shouldReceive('run')->with('sestatus 2>/dev/null')->andReturn(self::ENFORCING);
        $this->cli->shouldReceive('run')->with('command -v semanage', Mockery::type('callable'))->andReturn('/usr/sbin/semanage');
        $this->cli->shouldReceive('run')->with('semanage permissive -l -n 2>/dev/null')->andReturn("httpd_t\n");
        $this->cli->shouldNotReceive('run')->with('semanage permissive -a httpd_t', Mockery::any());
        $this->config->shouldNotReceive('set');

        $this->seLinux->install();
        $this->assertTrue(true);
    }

    #[Test]
    public function itWillInstallSemanageWhenMissing(): void
    {
        $this->cli->shouldReceive('run')->with('sestatus 2>/dev/null')->andReturn(self::ENFORCING);
        $this->cli->shouldReceive('run')->with('command -v semanage', Mockery::type('callable'))
            ->andReturnUsing(function (string $command, callable $onError) {
                $onError(1, '');

                return '';
            });
        $this->pm->shouldReceive('ensureInstalled')->with('policycoreutils-python-utils')->once();
        $this->cli->shouldReceive('run')->with('semanage permissive -l -n 2>/dev/null')->andReturn("httpd_t\n");

        $this->seLinux->install();
        $this->assertTrue(true);
    }

    #[Test]
    public function itWillFailWhenSemanageFails(): void
    {
        $this->cli->shouldReceive('run')->with('sestatus 2>/dev/null')->andReturn(self::ENFORCING);
        $this->cli->shouldReceive('run')->with('command -v semanage', Mockery::type('callable'))->andReturn('/usr/sbin/semanage');
        $this->cli->shouldReceive('run')->with('semanage permissive -l -n 2>/dev/null')->andReturn('');
        $this->cli->shouldReceive('run')->with('semanage permissive -a httpd_t', Mockery::type('callable'))
            ->andReturnUsing(function (string $command, callable $onError) {
                $onError(1, 'ValueError: denied');

                return '';
            });
        $this->config->shouldNotReceive('set');

        $this->expectException(DomainException::class);
        $this->seLinux->install();
    }

    #[Test]
    public function itWillOnlyUndoWhatInstallChanged(): void
    {
        $this->config->shouldReceive('get')->with('selinux_permissive_httpd')->andReturn(true);
        $this->cli->shouldReceive('run')->with('semanage permissive -d httpd_t', Mockery::type('callable'))->once()->andReturn('');
        $this->config->shouldReceive('set')->with('selinux_permissive_httpd', false)->once();

        $this->seLinux->uninstall();

        $other = Mockery::mock(Configuration::class);
        $other->shouldReceive('get')->with('selinux_permissive_httpd')->andReturn(null);
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldNotReceive('run');
        (new SeLinux($cli, $this->pm, $other))->uninstall();
        $this->assertTrue(true);
    }
}
