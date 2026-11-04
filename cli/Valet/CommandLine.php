<?php

namespace Valet;

use Symfony\Component\Process\Process;

class CommandLine
{
    private bool $root;

    public function __construct(?bool $root = null)
    {
        $this->root = $root ?? (function_exists('posix_geteuid') && posix_geteuid() === 0);
    }

    /**
     * Run the given command without output.
     */
    public function quietly(string $command, RunAs $as = RunAs::Current): void
    {
        $this->runCommand($command.' > /dev/null 2>&1', null, $as);
    }

    /**
     * Run the given command as the non-root user without output.
     */
    public function quietlyAsUser(string $command): void
    {
        $this->quietly($command, RunAs::User);
    }

    /**
     * Pass the command to the command line and display the output.
     */
    public function passthru(string $command, RunAs $as = RunAs::Current): void
    {
        passthru($this->prefix($as).$command);
    }

    /**
     * Run the given command.
     */
    public function run(string $command, ?callable $onError = null, RunAs $as = RunAs::Current): string
    {
        return $this->runCommand($command, $onError, $as);
    }

    /**
     * Run the given command as the non-root user.
     */
    public function runAsUser(string $command, ?callable $onError = null): string
    {
        return $this->runCommand($command, $onError, RunAs::User);
    }

    /**
     * The command prefix that switches to the given user. Valet usually runs
     * as root already; root needs no sudo, and some systems (ALT Linux) do not
     * list root in sudoers at all.
     */
    public function prefix(RunAs $as): string
    {
        return match ($as) {
            RunAs::Current => '',
            RunAs::Root => $this->root ? '' : 'sudo ',
            RunAs::User => $this->root ? 'runuser -u '.escapeshellarg(user()).' -- ' : '',
        };
    }

    /**
     * Run the given command.
     */
    private function runCommand(string $command, ?callable $onError, RunAs $as): string
    {
        $onError = $onError ?: function () {
        };

        $process = Process::fromShellCommandline($this->prefix($as).$command);

        $processOutput = '';
        $process->setTimeout(null)->run(function ($type, $line) use (&$processOutput) {
            $processOutput .= $line;
        });

        if ($process->getExitCode() > 0) {
            $onError($process->getExitCode(), $processOutput);
        }

        return $processOutput;
    }
}
