<?php

namespace Valet\Tests\Unit;

use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Valet\CommandLine;
use Valet\Contracts\PackageManager;
use Valet\PackageManagers\Apt;
use Valet\PackageManagers\Dnf;
use Valet\PackageManagers\PackageKit;
use Valet\PackageManagers\Pacman;
use Valet\PackageManagers\Yum;
use Valet\Tests\TestCase;

/**
 * The CA trust tools only trust plain PEM certificates from these directories.
 */
class CaTrustPathTest extends TestCase
{
    /**
     * @return array<string, array{class-string<PackageManager>, string, string}>
     */
    public static function managerProvider(): array
    {
        return [
            'Debian and Ubuntu' => [Apt::class, '/usr/local/share/ca-certificates', 'update-ca-certificates'],
            'Fedora' => [Dnf::class, '/etc/pki/ca-trust/source/anchors', 'update-ca-trust'],
            'RHEL with yum' => [Yum::class, '/etc/pki/ca-trust/source/anchors', 'update-ca-trust'],
            'Arch Linux' => [Pacman::class, '/etc/ca-certificates/trust-source/anchors', 'update-ca-trust'],
        ];
    }

    /**
     * @param class-string<PackageManager> $manager
     */
    #[Test]
    #[DataProvider('managerProvider')]
    public function itTrustsTheCertificateAuthorityFromAnAnchorDirectory(string $manager, string $path, string $command): void
    {
        $pm = new $manager(Mockery::mock(CommandLine::class));

        $this->assertSame($path, $pm->getCaCertificatesPath());
        $this->assertSame($command, $pm->getCaUpdateCommand());
    }

    #[Test]
    public function itUsesTheRedHatAnchorDirectoryWithPackageKitOutsideOpenSuse(): void
    {
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')->with('cat /etc/os-release 2>/dev/null')->andReturn("ID=\"centos\"\nID_LIKE=\"rhel fedora\"\n");

        $this->assertSame('/etc/pki/ca-trust/source/anchors', (new PackageKit($cli))->getCaCertificatesPath());
    }
}
