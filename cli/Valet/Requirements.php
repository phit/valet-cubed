<?php

namespace Valet;

use RuntimeException;

class Requirements
{
    /**
     * @var CommandLine
     */
    public $cli;

    /**
     * Create a new Warning instance.
     */
    public function __construct(CommandLine $cli)
    {
        $this->cli = $cli;
    }

    /**
     * Run all checks and output warnings.
     */
    public function check(): void
    {
        $this->homePathIsInsideRoot();
    }

    /**
     * Verify if valet home is inside /root directory.
     *
     * This usually means the HOME parameters has not been
     * kept using sudo.
     */
    private function homePathIsInsideRoot(): void
    {
        if (strpos(VALET_HOME_PATH, '/root/') === 0) {
            throw new RuntimeException('Valet home directory is inside /root');
        }
    }
}
