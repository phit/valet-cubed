<?php

namespace Valet\PackageManagers;

use ConsoleComponents\Writer;
use DomainException;
use Valet\CommandLine;
use Valet\Contracts\PackageManager;

class Dnf implements PackageManager
{
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var array
     */
    public const PHP_FPM_PATTERN_BY_VERSION = [
        '8.3' => 'php-fpm',
    ];

    private const PACKAGES = [
        'redis' => 'redis',
        'mysql' => 'mysql-server',
        'mariadb' => 'mariadb-server',
    ];

    private array $phpConventionCache = [];

    /**
     * Create a new Apt instance.
     */
    public function __construct(CommandLine $cli)
    {
        $this->cli = $cli;
    }

    /**
     * Determine if the given package is installed.
     */
    public function installed(string $package): bool
    {
        $escaped = escapeshellarg($package);
        $output = $this->cli->run("rpm -q {$escaped} >/dev/null 2>&1 && echo ok || echo no");

        return trim($output) === 'ok';
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

        $this->cli->run(trim('dnf install -y ' . $package), function ($exitCode, $errorOutput) use ($package) {
            Writer::error(\sprintf('%s: %s', $exitCode, $errorOutput));

            throw new DomainException('Dnf was unable to install [' . $package . '].');
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
            $output = $this->cli->run('which dnf', function () {
                throw new DomainException('Dnf not available');
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
        return $this->resolvePhpConvention($version) === 'remi-scl'
            ? 'php' . str_replace('.', '', $version) . '-php-fpm'
            : 'php-fpm';
    }

    /**
     * Get the `ca-certificates` directory
     */
    public function getCaCertificatesPath(): string
    {
        return '/usr/share/pki/ca-trust-source';
    }

    public function getPhpFpmServiceName(string $version): string
    {
        return $this->getPhpFpmName($version);
    }

    public function getPhpExtensionName(string $version, string $extension): string
    {
        return $this->getPhpExtensionPrefix($version) . $extension;
    }

    /**
     * Get the command to update CA certificates
     */
    public function getCaUpdateCommand(): string
    {
        return 'sudo update-ca-trust';
    }

    /**
     * Determine php extension pattern.
     */
    public function getPhpExtensionPrefix(string $version): string
    {
        return $this->resolvePhpConvention($version) === 'remi-scl'
            ? 'php' . str_replace('.', '', $version) . '-php-'
            : 'php-';
    }

    private function resolvePhpConvention(string $version): string
    {
        if (!preg_match('/\A\d+\.\d+\z/', $version)) {
            throw new \InvalidArgumentException('Invalid PHP version.');
        }
        if (isset($this->phpConventionCache[$version])) {
            return $this->phpConventionCache[$version];
        }

        $package = 'php' . str_replace('.', '', $version) . '-php-fpm';
        if ($this->installed($package)) {
            return $this->phpConventionCache[$version] = 'remi-scl';
        }

        $nativeVersion = trim($this->cli->run("rpm -q --queryformat '%{VERSION}' php-fpm 2>/dev/null || true"));
        if (preg_match('/\A' . preg_quote($version, '/') . '(?:\.|\z)/', $nativeVersion)) {
            return $this->phpConventionCache[$version] = 'native';
        }

        $output = $this->cli->run('dnf info ' . escapeshellarg($package) . ' >/dev/null 2>&1 && echo ok || echo no');
        return $this->phpConventionCache[$version] = (trim($output) === 'ok' ? 'remi-scl' : 'native');
    }

    /**
     * Get package name by service.
     */
    public function packageName(string $name): string
    {
        if (isset(self::PACKAGES[$name])) {
            return self::PACKAGES[$name];
        }
        throw new \InvalidArgumentException(\sprintf('Package not found by %s', $name));
    }
}
