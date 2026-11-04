<?php

namespace Valet\Tests\Unit;

use Valet\CommandLine;
use Valet\RunAs;
use Valet\Tests\TestCase;

use function Valet\user;

class CommandLineTest extends TestCase
{
    public function prefixProvider(): array
    {
        return [
            'root needs no sudo' => [true, RunAs::Root, ''],
            'root switches to the user with runuser' => [true, RunAs::User, 'runuser -u ' . escapeshellarg(user()) . ' -- '],
            'the user elevates with sudo' => [false, RunAs::Root, 'sudo '],
            'the user runs user commands directly' => [false, RunAs::User, ''],
            'current user as root' => [true, RunAs::Current, ''],
            'current user as the user' => [false, RunAs::Current, ''],
        ];
    }

    /**
     * @dataProvider prefixProvider
     */
    public function testPrefixDependsOnTheCurrentUser(bool $root, RunAs $as, string $expected): void
    {
        $this->assertSame($expected, (new CommandLine($root))->prefix($as));
    }

    public function testRunsTheCommandWithThePrefix(): void
    {
        $this->assertSame('ok', trim((new CommandLine(true))->run('echo ok', null, RunAs::Root)));
    }
}
