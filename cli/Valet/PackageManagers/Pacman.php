<?php

namespace Valet\PackageManagers;

use ConsoleComponents\Writer;
use DomainException;
use Valet\CommandLine;
use Valet\Contracts\PackageManager;

class Pacman implements PackageManager
{
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * Extensions the official php package does not include, by Valet's name.
     * Everything else is compiled in or ships as a module of php itself.
     */
    private const OFFICIAL_EXTENSION_PACKAGES = [
        'gd' => 'php-gd',
        'pgsql' => 'php-pgsql',
        'sqlite' => 'php-sqlite',
        'redis' => 'php-redis',
    ];

    /**
     * Modules to load per extension; Arch ships shared modules disabled.
     */
    private const EXTENSION_MODULES = [
        'mysql' => ['mysqli', 'pdo_mysql'],
        'pgsql' => ['pgsql', 'pdo_pgsql'],
        'sqlite' => ['sqlite3', 'pdo_sqlite'],
    ];

    private ?string $officialPhpVersion = null;

    private const PACKAGES = [
        'redis' => 'redis',
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
    ];

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
        $query = "pacman -Qqs {$package}";

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

        $this->cli->run(
            trim('pacman --noconfirm --needed -S '.$package),
            function ($exitCode, $errorOutput) use ($package) {
                Writer::error(\sprintf('%s: %s', $exitCode, $errorOutput));
                if (preg_match('/target not found: (php\d+\S*)/', $errorOutput, $matches)) {
                    Writer::warn(\sprintf(
                        'The official repositories only provide PHP %s. Other versions such as %s come from the AUR: '
                        . 'install them with an AUR helper (for example "yay -S %s") and run the command again.',
                        $this->officialPhpVersion() ?? '(unknown)',
                        $matches[1],
                        $matches[1]
                    ));
                }

                throw new DomainException('Pacman was unable to install ['.$package.'].');
            }
        );
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
            $output = $this->cli->run('which pacman', function () {
                throw new DomainException('Pacman not available');
            });

            return $output != '';
        } catch (DomainException $e) {
            return false;
        }
    }

    /**
     * Determine php fpm package name. The official repositories carry one PHP
     * version as "php"; other versions are AUR packages named php85-fpm etc.
     */
    public function getPhpFpmName(string $version): string
    {
        if ($this->isOfficialPhp($version)) {
            return 'php-fpm';
        }

        return 'php' . preg_replace('~[^\d]~', '', $version) . '-fpm';
    }

    /**
     * Get the `ca-certificates` directory
     */
    public function getCaCertificatesPath(): string
    {
        return '/etc/ca-certificates/trust-source/anchors';
    }

    public function getPhpFpmServiceName(string $version): string
    {
        return $this->getPhpFpmName($version);
    }

    public function getPhpExtensionName(string $version, string $extension): string
    {
        if ($this->isOfficialPhp($version)) {
            return self::OFFICIAL_EXTENSION_PACKAGES[$extension] ?? 'php';
        }

        return $this->getPhpExtensionPrefix($version) . $extension;
    }

    /**
     * Load the given extensions' modules for this PHP version. Arch installs
     * shared modules (pdo_mysql, gd, intl, zip, ...) without enabling them.
     *
     * @param list<string> $extensions
     */
    public function enablePhpExtensions(string $version, array $extensions): void
    {
        $official = $this->isOfficialPhp($version);
        $suffix = $official ? '' : preg_replace('~[^\d]~', '', $version);
        $modules = '/usr/lib/php' . $suffix . '/modules';
        $iniFile = '/etc/php' . $suffix . '/conf.d/valet.ini';

        $loaded = array_map('strtolower', array_map('trim', explode(PHP_EOL, $this->cli->run('php' . $suffix . ' -m 2>/dev/null'))));
        $available = explode(PHP_EOL, $this->cli->run('ls ' . escapeshellarg($modules) . ' 2>/dev/null'));
        $enable = [];
        foreach ($extensions as $extension) {
            foreach (self::EXTENSION_MODULES[$extension] ?? [$extension] as $module) {
                if (in_array($module . '.so', $available, true) && !in_array($module, $loaded, true)) {
                    $enable[] = 'extension=' . $module;
                }
            }
        }
        if ($enable === []) {
            return;
        }

        $this->cli->run(\sprintf(
            'printf %s %s > %s',
            escapeshellarg('%s\n'),
            implode(' ', array_map('escapeshellarg', array_merge(['; Enabled by Valet Cubed'], $enable))),
            escapeshellarg($iniFile)
        ));
    }

    /**
     * Whether the official "php" package provides this version.
     */
    private function isOfficialPhp(string $version): bool
    {
        return $this->officialPhpVersion() === $version;
    }

    /**
     * The PHP version of the official "php" package, e.g. "8.5".
     */
    private function officialPhpVersion(): ?string
    {
        if ($this->officialPhpVersion === null) {
            $output = $this->cli->run('pacman -Si php 2>/dev/null || pacman -Qi php 2>/dev/null');
            $this->officialPhpVersion = preg_match('/^Version\s*:\s*(?:\d+:)?(\d+\.\d+)/m', $output, $matches) ? $matches[1] : '';
        }

        return $this->officialPhpVersion === '' ? null : $this->officialPhpVersion;
    }

    /**
     * Get the command to update CA certificates
     */
    public function getCaUpdateCommand(): string
    {
        return 'update-ca-trust';
    }

    /**
     * Determine php extension pattern.
     */
    public function getPhpExtensionPrefix(string $version): string
    {
        $version = preg_replace('~[^\d]~', '', $version);
        $pattern = 'php{VERSION_WITHOUT_DOT}-';
        return str_replace('{VERSION_WITHOUT_DOT}', $version, $pattern);
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
