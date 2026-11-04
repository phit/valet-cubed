<?php

namespace Valet\Tests\Unit;

use Mockery;
use Valet\CommandLine;
use Valet\Requirements;
use Valet\Tests\TestCase;

class RequirementsTest extends TestCase
{
    /**
     * @test
     */
    public function itWillPassForAHomeOutsideRoot(): void
    {
        $commandLine = Mockery::mock(CommandLine::class);
        $commandLine->shouldNotReceive('run');

        (new Requirements($commandLine))->check();

        $this->assertTrue(true);
    }
}
