<?php

namespace Valet;

use DomainException;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\Configuration;
use Valet\Facades\SiteProxy as SiteProxyFacade;

class Mailpit
{
    /**
     * @var PackageManager
     */
    public $pm;
    /**
     * @var ServiceManager
     */
    public $sm;
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var Filesystem
     */
    public $files;
    /**
     * @var string
     */
    public const SERVICE_NAME = 'mailpit';

    /**
     * Create a new Mailpit instance.
     *
     * @return void
     */
    public function __construct(PackageManager $pm, ServiceManager $sm, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->files = $files;
    }

    /**
     * Install the configuration files for Mailpit.
     */
    public function install(): void
    {
        $this->ensureInstalled();

        $this->createService();

        $this->sm->start(self::SERVICE_NAME);

        $this->removeMailHog();
    }

    /**
     * Remove MailHog, which releases before 2.0 installed as a service with
     * a secured nginx site. Each part is removed on its own: an upgraded
     * home can hold the site without its certificate, which stops nginx.
     */
    private function removeMailHog(): void
    {
        $units = array_filter(
            ['/etc/systemd/system/mailhog.service', '/etc/init.d/mailhog'],
            fn (string $path) => $this->files->exists($path)
        );
        if ($units !== []) {
            try {
                $this->sm->stop('mailhog');
                $this->sm->disable('mailhog');
            } catch (DomainException $e) {
            }
            foreach ($units as $unit) {
                $this->files->unlink($unit);
            }
            $this->cli->run('systemctl daemon-reload', null, RunAs::Root);
        }
        $this->files->unlink('/opt/valet-linux/mailhog');

        $site = 'mailhog.' . Configuration::get('domain');
        $this->files->unlink(VALET_HOME_PATH . '/Nginx/' . $site);
        foreach (['crt', 'key', 'csr', 'conf'] as $extension) {
            $this->files->unlink(VALET_HOME_PATH . '/Certificates/' . $site . '.' . $extension);
        }
    }

    /**
     * Start the Mailpit service.
     */
    public function start(): void
    {
        $this->sm->start(self::SERVICE_NAME);
    }

    /**
     * Restart the Mailpit service.
     */
    public function restart(): void
    {
        $this->sm->restart(self::SERVICE_NAME);
    }

    /**
     * Stop the Mailpit service.
     */
    public function stop(): void
    {
        $this->sm->stop(self::SERVICE_NAME);
    }

    /**
     * Mailpit service status.
     */
    public function status(): void
    {
        $this->sm->printStatus(self::SERVICE_NAME);
    }

    /**
     * Prepare Mailpit for uninstall.
     */
    public function uninstall(): void
    {
        $this->stop();
    }

    /**
     * Validate if system already has Mailpit installed in it.
     */
    private function ensureInstalled(): void
    {
        if (!$this->isAvailable()) {
            $this->cli->runAsUser(
                'curl -sL https://raw.githubusercontent.com/axllent/mailpit/develop/install.sh | bash'
            );
        }
    }

    /**
     * Create Mailpit service files
     */
    private function createService(): void
    {
        $servicePath = '/etc/init.d/mailpit';
        $serviceFile = VALET_ROOT_PATH . '/cli/stubs/init/mailpit.sh';
        $hasSystemd = $this->sm->isSystemd();

        if ($hasSystemd) {
            $servicePath = '/etc/systemd/system/mailpit.service';
            $serviceFile = VALET_ROOT_PATH . '/cli/stubs/init/mailpit';
        }

        $this->files->put(
            $servicePath,
            $this->files->get($serviceFile)
        );

        if (!$hasSystemd) {
            $this->cli->run("chmod +x $servicePath");
        }

        $this->sm->enable(self::SERVICE_NAME);

        $this->updateDomain();
    }

    /**
     * Update domain for HTTP access.
     */
    private function updateDomain(): void
    {
        $domain = Configuration::get('domain');

        SiteProxyFacade::proxyCreate("mails.$domain", 'http://localhost:8025', true);
    }

    private function isAvailable(): bool
    {
        try {
            $output = $this->cli->run(
                'which mailpit',
                function () {
                    throw new DomainException('Service not available');
                }
            );

            return $output != '';
        } catch (DomainException $e) {
            return false;
        }
    }
}
