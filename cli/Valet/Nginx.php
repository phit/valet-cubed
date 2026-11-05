<?php

namespace Valet;

use ConsoleComponents\Writer;
use Illuminate\Support\Collection;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\PhpFpm as PhpFpmFacade;

class Nginx
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
     * @var Configuration
     */
    public $configuration;
    /**
     * @var SiteSecure
     */
    public $siteSecure;
    /**
     * @var string
     */
    public const NGINX_CONF = '/etc/nginx/nginx.conf';
    public const SITES_AVAILABLE_CONF = '/etc/nginx/sites-available/valet.conf';
    public const SITES_ENABLED_CONF = '/etc/nginx/sites-enabled/valet.conf';
    public const SERVICE_OVERRIDE = '/etc/systemd/system/nginx.service.d/valet-cubed.conf';
    // Name used before the rename to Valet Cubed.
    public const FORMER_SERVICE_OVERRIDE = '/etc/systemd/system/nginx.service.d/valet-linux-plus.conf';

    /**
     * Create a new Nginx instance.
     *
     * @return void
     */
    public function __construct(
        PackageManager $pm,
        ServiceManager $sm,
        CommandLine $cli,
        Filesystem $files,
        Configuration $configuration,
        SiteSecure $siteSecure
    ) {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->siteSecure = $siteSecure;
        $this->files = $files;
        $this->configuration = $configuration;
    }

    /**
     * Install the configuration files for Nginx.
     */
    public function install(): void
    {
        $this->pm->ensureInstalled('nginx');
        $this->sm->enable('nginx');
        $this->handleApacheService();
        $this->files->ensureDirExists('/etc/nginx/sites-available');
        $this->files->ensureDirExists('/etc/nginx/sites-enabled');

        $this->stop();
        $this->installServiceOverride();
        $this->installConfiguration();
        $this->installServer();
        $this->installNginxDirectory();
    }

    /**
     * Update the port used by Nginx.
     */
    public function updatePort(string $newPort): void
    {
        $valetConfig = strArrayReplace([
            'VALET_HOME_PATH'   => VALET_HOME_PATH,
            'VALET_SERVER_PATH' => VALET_SERVER_PATH,
            'VALET_PORT'        => $newPort,
        ], $this->files->get(VALET_ROOT_PATH . '/cli/stubs/valet.conf'));
        $this->files->putAsUser(self::SITES_AVAILABLE_CONF, $valetConfig);
    }

    /**
     * Restart the Nginx service.
     */
    public function restart(): void
    {
        $this->sm->restart('nginx');
    }

    /**
     * Stop the Nginx service.
     */
    public function stop(): void
    {
        $this->sm->stop('nginx');
    }

    /**
     * Nginx service status.
     */
    public function status(): void
    {
        $this->sm->printStatus('nginx');
    }

    /**
     * Prepare Nginx for uninstall.
     */
    public function uninstall(): void
    {
        $this->stop();
        $this->removeServiceOverrides([self::SERVICE_OVERRIDE, self::FORMER_SERVICE_OVERRIDE]);
        $this->files->restore(self::NGINX_CONF);
        $this->files->restore('/etc/nginx/fastcgi_params');
        $this->files->unlink(self::SITES_ENABLED_CONF);
        $this->files->unlink(self::SITES_AVAILABLE_CONF);

        if ($this->files->exists('/etc/nginx/sites-available/default')) {
            $this->files->symlink('/etc/nginx/sites-available/default', '/etc/nginx/sites-enabled/default');
        }
    }

    /**
     * Return a list of all sites with explicit Nginx configurations.
     */
    public function configuredSites(): Collection
    {
        return collect($this->files->scandir(VALET_HOME_PATH . '/Nginx'))
            ->reject(function ($file) {
                return str_starts_with($file, '.');
            });
    }

    /**
     * Install the Valet Nginx server configuration file.
     *
     * @param string|float|null $phpVersion
     */
    public function installServer($phpVersion = null): void
    {
        $valetConf = strArrayReplace([
            'VALET_HOME_PATH'       => VALET_HOME_PATH,
            'VALET_FPM_SOCKET_FILE' => VALET_HOME_PATH . '/' . PhpFpmFacade::socketFileName($phpVersion),
            'VALET_SERVER_PATH'     => VALET_SERVER_PATH,
            'VALET_STATIC_PREFIX'   => VALET_STATIC_PREFIX,
            'VALET_PORT'            => $this->configuration->get('port'),
        ], $this->files->get(VALET_ROOT_PATH . '/cli/stubs/valet.conf'));
        $this->files->putAsUser(self::SITES_AVAILABLE_CONF, $valetConf);

        if ($this->files->exists('/etc/nginx/sites-enabled/default')) {
            $this->files->unlink('/etc/nginx/sites-enabled/default');
        }

        $this->cli->run(\sprintf("ln -snf %s %s", self::SITES_AVAILABLE_CONF, self::SITES_ENABLED_CONF));
        $this->files->backup('/etc/nginx/fastcgi_params');

        $this->files->putAsUser(
            '/etc/nginx/fastcgi_params',
            $this->files->get(VALET_ROOT_PATH . '/cli/stubs/fastcgi_params')
        );
    }

    /**
     * Generate fresh Nginx servers for existing secure sites.
     */
    private function rewriteSecureNginxFiles(): void
    {
        $domain = $this->configuration->get('domain');

        $count = $this->siteSecure->secured()->count();
        if ($count > 0) {
            Writer::info(sprintf('Renewing certificates for %d secured site%s...', $count, $count === 1 ? '' : 's'));
        }

        $this->siteSecure->reSecureForNewDomain($domain, $domain);
        $this->siteSecure->rebuildUnsecuredProxies();
    }

    /**
     * Point every site configuration at this installation's router, without
     * replacing custom configuration. Secured, isolated and proxied sites keep
     * their configuration across installs, so an old path would outlive a move.
     */
    private function rewriteServerPathInNginxFiles(): void
    {
        foreach ($this->configuredSites() as $site) {
            $path = VALET_HOME_PATH . '/Nginx/' . $site;
            $contents = $this->files->get($path);

            $updated = preg_replace_callback(
                '~^(\s*(?:rewrite\s+\^\s+|error_page\s+404\s+|fastcgi_index\s+|fastcgi_param\s+SCRIPT_FILENAME\s+)"?)/[^\s;"]*/server\.php(?="|\s|;)~m',
                static fn ($match) => $match[1] . VALET_SERVER_PATH,
                $contents
            );
            if ($updated !== null && $updated !== $contents) {
                $this->files->putAsUser($path, $updated);
            }
        }
    }

    /**
     * Disable Apache2 Service.
     */
    private function handleApacheService(): void
    {
        if (!$this->pm->installed('apache2')) {
            return;
        }
        if (!$this->sm->disabled('apache2')) {
            $this->sm->disable('apache2');
        }
        $this->sm->stop('apache2');
    }

    /**
     * Install the Nginx configuration file.
     */
    private function installConfiguration(): void
    {
        $contents = $this->files->get(VALET_ROOT_PATH . '/cli/stubs/nginx.conf');
        $nginxConfig = self::NGINX_CONF;

        $pidPath = 'pid /run/nginx.pid';
        $hasPIDOption = strpos($this->cli->run('cat /lib/systemd/system/nginx.service'), 'pid /');

        if ($hasPIDOption) {
            $pidPath = '# pid /run/nginx.pid';
        }

        $this->files->backup($nginxConfig);

        $this->files->putAsUser(
            $nginxConfig,
            strArrayReplace([
                'VALET_USER'      => user(),
                'VALET_GROUP'     => group(),
                'VALET_HOME_PATH' => VALET_HOME_PATH,
                'VALET_PID'       => $pidPath,
            ], $contents)
        );
    }

    private function installServiceOverride(): void
    {
        $this->removeServiceOverrides([self::FORMER_SERVICE_OVERRIDE]);
        // openSUSE's unit mounts /home read-only for nginx, but Valet serves
        // sites from, and logs to, the user's home directory.
        $protectHome = trim($this->cli->run('systemctl show nginx.service -p ProtectHome --value 2>/dev/null'));
        if (!in_array($protectHome, ['yes', 'read-only', 'tmpfs'], true)) {
            return;
        }
        $this->files->ensureDirExists(dirname(self::SERVICE_OVERRIDE));
        $this->files->put(self::SERVICE_OVERRIDE, "# Valet Cubed serves sites from the home directory.\n[Service]\nProtectHome=no\n");
        $this->cli->run('systemctl daemon-reload', null, RunAs::Root);
    }

    /**
     * @param list<string> $paths
     */
    private function removeServiceOverrides(array $paths): void
    {
        $removed = false;
        foreach ($paths as $path) {
            if ($this->files->exists($path)) {
                $this->files->unlink($path);
                $removed = true;
            }
        }
        if ($removed) {
            $this->cli->run('systemctl daemon-reload', null, RunAs::Root);
        }
    }

    /**
     * Install the Nginx configuration directory to the ~/.valet directory.
     *
     * This directory contains all site-specific Nginx servers.
     */
    private function installNginxDirectory(): void
    {
        if (!$this->files->isDir($nginxDirectory = VALET_HOME_PATH . '/Nginx')) {
            $this->files->mkdirAsUser($nginxDirectory);
        }

        $this->files->putAsUser($nginxDirectory . '/.keep', "\n");

        $this->rewriteSecureNginxFiles();
        $this->rewriteServerPathInNginxFiles();
    }
}
