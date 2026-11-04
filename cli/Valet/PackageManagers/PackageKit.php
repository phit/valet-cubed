<?php

namespace Valet\PackageManagers;

use ConsoleComponents\Writer;
use DomainException;
use Valet\CommandLine;
use Valet\Contracts\PackageManager;

class PackageKit implements PackageManager
{
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var array
     */
    public const PHP_FPM_PATTERN_BY_VERSION = [];

    private const PACKAGES = [
        'redis' => 'redis-server',
        'mysql' => 'mysql-server',
        'mariadb' => 'mariadb-server',
    ];

    /**
     * openSUSE, the main PackageKit user, names packages differently. Leap
     * ships MariaDB only.
     */
    private const SUSE_PACKAGES = [
        'redis' => 'redis',
        'mysql' => 'mariadb',
        'mariadb' => 'mariadb',
    ];

    private ?bool $suse = null;

    /**
     * Create a new Apt instance.
     */
    public function __construct(CommandLine $cli)
    {
        $this->cli = $cli;
    }

    /**
     * Get array of installed packages.
     */
    public function packages(string $package): array
    {
        $query = "pkcon search {$package} | grep '^In' | sed 's/\s\+/ /g' | cut -d' ' -f2 | sed 's/-[0-9].*//'";

        return explode(PHP_EOL, $this->cli->run($query));
    }

    /**
     * Determine if the given package is installed.
     */
    public function installed(string $package): bool
    {
        return in_array($package, $this->packages($package));
    }

    /**
     * Ensure that the given package is installed.
     */
    public function ensureInstalled(string $package): void
    {
        if (!$this->installed($package)) {
            $this->installOrFail($package);
        }
    }

    /**
     * Install the given package and throw an exception on failure.
     */
    public function installOrFail(string $package): void
    {
        Writer::twoColumnDetail($package, 'Installing');

        $this->cli->run(trim('pkcon install -y '.$package), function ($exitCode, $errorOutput) use ($package) {
            Writer::error(\sprintf('%s: %s', $exitCode, $errorOutput));

            throw new DomainException('PackageKit was unable to install ['.$package.'].');
        });
    }

    /**
     * Configure package manager on valet install.
     */
    public function setup(): void
    {
        // Nothing to do
    }

    /**
     * Determine if package manager is available on the system.
     */
    public function isAvailable(): bool
    {
        try {
            $output = $this->cli->run('which pkcon', function () {
                throw new DomainException('PackageKit not available');
            });

            return $output != '';
        } catch (DomainException $e) {
            return false;
        }
    }

    /**
     * Determine php fpm package name.
     */
    public function getPhpFpmName(string $version): string
    {
        if ($this->suse()) {
            return $this->getPhpExtensionPrefix($version) . 'fpm';
        }
        $pattern = !empty(self::PHP_FPM_PATTERN_BY_VERSION[$version])
            ? self::PHP_FPM_PATTERN_BY_VERSION[$version] : 'php{VERSION}-fpm';

        return str_replace('{VERSION}', $version, $pattern);
    }

    /**
     * Get the `ca-certificates` directory
     */
    public function getCaCertificatesPath(): string
    {
        return $this->suse() ? '/etc/pki/trust/anchors' : '/etc/pki/ca-trust/source/anchors';
    }

    public function getPhpFpmServiceName(string $version): string
    {
        return $this->suse() ? 'php-fpm' : $this->getPhpFpmName($version);
    }

    public function getPhpExtensionName(string $version, string $extension): string
    {
        if ($this->suse() && $extension === 'xml') {
            $extension = 'dom';
        }

        return $this->getPhpExtensionPrefix($version) . $extension;
    }

    /**
     * Get the command to update CA certificates
     */
    public function getCaUpdateCommand(): string
    {
        return $this->suse() ? 'update-ca-certificates' : 'update-ca-trust';
    }

    /**
     * Determine php extension pattern.
     */
    public function getPhpExtensionPrefix(string $version): string
    {
        // openSUSE packages one PHP per major version: php8-fpm, php8-gd.
        if ($this->suse()) {
            return 'php' . strtok($version, '.') . '-';
        }
        $pattern = 'php{VERSION}-';
        return str_replace('{VERSION}', $version, $pattern);
    }

    /**
     * Get package name by service.
     */
    public function packageName(string $name): string
    {
        $packages = $this->suse() ? self::SUSE_PACKAGES : self::PACKAGES;
        if (isset($packages[$name])) {
            return $packages[$name];
        }
        throw new \InvalidArgumentException(\sprintf('Package not found by %s', $name));
    }

    private function suse(): bool
    {
        if ($this->suse === null) {
            $osRelease = $this->cli->run('cat /etc/os-release 2>/dev/null');
            $this->suse = preg_match('/^ID(_LIKE)?="?[^"\n]*suse/mi', $osRelease) === 1;
        }

        return $this->suse;
    }
}
