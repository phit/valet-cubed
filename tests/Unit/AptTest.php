<?php

namespace Valet\Tests\Unit;

use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Valet\CommandLine;
use Valet\PackageManagers\Apt;
use Valet\Tests\TestCase;

class AptTest extends TestCase
{
    public static function candidateProvider(): array
    {
        return [
            'available' => ["mysql-server:\n  Installed: (none)\n  Candidate: 8.0.45-0ubuntu0.24.04.1\n", true],
            'no candidate' => ["mysql-server:\n  Installed: (none)\n  Candidate: (none)\n", false],
            'unknown package' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('candidateProvider')]
    public function itWillDetectWhetherThePackageIsAvailable(string $policy, bool $expected): void
    {
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')->with("apt-cache policy 'mysql-server' 2>/dev/null")->once()->andReturn($policy);

        $this->assertSame($expected, (new Apt($cli))->hasInstallCandidate('mysql-server'));
    }
}
