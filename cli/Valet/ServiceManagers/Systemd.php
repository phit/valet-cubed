<?php

namespace Valet\ServiceManagers;

use ConsoleComponents\Writer;
use DomainException;
use Valet\CommandLine;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;
use Valet\RunAs;

class Systemd implements ServiceManager
{
    private const SERVICE_ALIASES = [
        'mysql' => ['mysql', 'mysqld'],
        'mariadb' => ['mariadb', 'mysql', 'mysqld'],
        'mysqld' => ['mysqld', 'mysql'],
    ];
    /**
     * @var CommandLine
     */
    private $cli;
    /**
     * @var Filesystem
     */
    private $files;

    /**
     * Create a new Systemd instance.
     */
    public function __construct(CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
    }

    /**
     * Start the given services.
     * @param string|string[]|null $services Service name
     */
    public function start(array|string|null $services): void
    {
        /** @var string[] $services */
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            Writer::twoColumnDetail(ucfirst($service), 'Starting');
            $this->cli->quietly('systemctl start '.$this->getRealService($service), RunAs::Root);
        }
    }

    /**
     * Stop the given services.
     * @param string|string[]|null $services Service name
     */
    public function stop(array|string|null $services): void
    {
        /** @var string[] $services */
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            Writer::twoColumnDetail(ucfirst($service), 'Stopping');
            $this->cli->quietly('systemctl stop '.$this->getRealService($service), RunAs::Root);
        }
    }

    /**
     * Restart the given services.
     * @param string|string[]|null $services Service name
     */
    public function restart(array|string|null $services): void
    {
        /** @var string[] $services */
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            Writer::twoColumnDetail(ucfirst($service), 'Restarting');
            $this->cli->quietly('systemctl restart '.$this->getRealService($service), RunAs::Root);
        }
    }

    /**
     * Status of the given services.
     */
    public function printStatus(string $service): void
    {
        $status = $this->cli->run('systemctl status '.$this->getRealService($service).' | grep "Active:"');
        $running = strpos(trim($status), 'running');

        if ($running) {
            Writer::info(ucfirst($service).' is running...');
        } else {
            Writer::warn(ucfirst($service).' is stopped...');
        }
    }

    /**
     * Check if service is disabled.
     */
    public function disabled(string $service): bool
    {
        $service = $this->getRealService($service);

        return !str_contains(trim($this->cli->run(\sprintf('systemctl is-enabled %s', $service))), 'enabled');
    }

    /**
     * Enable services.
     */
    public function enable(string $service): void
    {
        try {
            $service = $this->getRealService($service);

            if ($this->disabled($service)) {
                $this->cli->quietly('systemctl enable '.$service, RunAs::Root);
            }

            Writer::twoColumnDetail(ucfirst($service), 'Enabled');
        } catch (DomainException $e) {
            Writer::warn(ucfirst($service).' unavailable.');
        }
    }

    /**
     * Disable services.
     */
    public function disable(string $service): void
    {
        try {
            $service = $this->getRealService($service);

            if (!$this->disabled($service)) {
                $this->cli->quietly('systemctl disable '.$service, RunAs::Root);
            }

            Writer::twoColumnDetail(ucfirst($service), 'Disabled');
        } catch (DomainException $e) {
            Writer::warn(ucfirst($service).' unavailable.');
        }
    }

    /**
     * Determine if service manager is available on the system.
     */
    public function isAvailable(): bool
    {
        try {
            $output = $this->cli->run(
                'which systemctl',
                function () {
                    throw new DomainException('Systemd not available');
                }
            );

            return $output != '';
        } catch (DomainException $e) {
            return false;
        }
    }

    /**
     * Remove Valet DNS services.
     */
    public function removeValetDns(): void
    {
        $servicePath = '/etc/systemd/system/valet-dns.service';
        if ($this->files->exists($servicePath)) {
            Writer::info('Removing Valet DNS service...');
            $this->disable('valet-dns');
            $this->stop('valet-dns');
            $this->files->remove($servicePath);
        }
    }

    public function isSystemd(): bool
    {
        return true;
    }

    /**
     * Determine real service name.
     * @throws DomainException
     */
    private function getRealService(string $service): string
    {
        if (!preg_match('/\A[a-zA-Z0-9_@.:-]+\z/', $service) || $service[0] === '-' || $service === '.' || $service === '..') {
            throw new DomainException('Invalid service name.');
        }
        foreach (self::SERVICE_ALIASES[$service] ?? [$service] as $candidate) {
            if (str_contains($this->cli->run('systemctl status ' . escapeshellarg($candidate) . ' | grep Loaded'), 'Loaded: loaded')) {
                return $candidate;
            }
        }

        throw new DomainException('Unable to determine service name.');
    }
}
