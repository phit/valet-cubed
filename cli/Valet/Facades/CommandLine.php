<?php

namespace Valet\Facades;

/**
 * Class CommandLine.
 *
 * @method static void   quietly(string $command, \Valet\RunAs $as = \Valet\RunAs::Current)
 * @method static void   quietlyAsUser(string $command)
 * @method static void   passthru(string $command, \Valet\RunAs $as = \Valet\RunAs::Current)
 * @method static string run(string $command, ?callable $onError = null, \Valet\RunAs $as = \Valet\RunAs::Current)
 * @method static string runAsUser(string $command, ?callable $onError = null)
 */
class CommandLine extends Facade
{
}
