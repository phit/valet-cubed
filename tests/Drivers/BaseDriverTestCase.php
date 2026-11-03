<?php

namespace Valet\Tests\Drivers;

use PHPUnit\Framework\TestCase;

class BaseDriverTestCase extends TestCase
{
    public function setUp(): void
    {
        $_SERVER['HTTP_HOST'] = 'this is set in Valet requests but not phpunit';
    }

    /**
     * Provide the list of project fixture directory names.
     *
     * Implemented with plain PHP (scandir) rather than Valet\Filesystem to avoid
     * a static/instance mismatch with the fork's Filesystem::scandir.
     *
     * @return array<int, string>
     */
    public function projects(): array
    {
        $entries = scandir(__DIR__.'/projects') ?: [];

        return array_values(array_filter($entries, function ($file) {
            return ! in_array($file, ['.', '..', '.keep', '.gitkeep', '.DS_Store'], true)
                && is_dir(__DIR__.'/projects/'.$file);
        }));
    }

    public function projectDir(string $name): string
    {
        return __DIR__.'/projects/'.$name;
    }
}
