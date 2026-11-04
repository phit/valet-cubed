<?php

namespace Valet;

use ConsoleComponents\Writer;
use DomainException;
use Valet\Contracts\PackageManager;

/**
 * Lets nginx and PHP-FPM serve from the home directory under SELinux.
 *
 * Valet keeps its configuration, logs and PHP-FPM socket in the user's home,
 * which the targeted policy does not allow the web server domain to use.
 * Instead of relabelling those paths, Valet makes the httpd_t domain (nginx
 * and PHP-FPM) permissive. Everything else stays enforced.
 */
class SeLinux
{
    private const DOMAIN = 'httpd_t';
    private const CONFIG_KEY = 'selinux_permissive_httpd';

    public function __construct(
        public CommandLine $cli,
        public PackageManager $pm,
        public Configuration $configuration
    ) {
    }

    public function enforcing(): bool
    {
        $output = $this->cli->run('sestatus 2>/dev/null');

        return preg_match('@SELinux status:\s+enabled@', $output) === 1
            && preg_match('@Current mode:\s+enforcing@', $output) === 1;
    }

    public function install(): void
    {
        if (!$this->enforcing()) {
            return;
        }
        $this->ensureSemanage();
        if ($this->permissive()) {
            return;
        }

        Writer::twoColumnDetail('SELinux', 'Making nginx and PHP-FPM (' . self::DOMAIN . ') permissive');
        $this->cli->run('semanage permissive -a ' . self::DOMAIN, function ($exitCode, $output) {
            throw new DomainException(\sprintf('Unable to make %s permissive (%s): %s', self::DOMAIN, $exitCode, trim($output)));
        });
        $this->configuration->set(self::CONFIG_KEY, true);
    }

    public function uninstall(): void
    {
        if ($this->configuration->get(self::CONFIG_KEY) !== true) {
            return;
        }

        Writer::twoColumnDetail('SELinux', 'Restoring enforcement for nginx and PHP-FPM (' . self::DOMAIN . ')');
        $this->cli->run('semanage permissive -d ' . self::DOMAIN, function ($exitCode, $output) {
            Writer::warn(\sprintf('Unable to restore %s: %s. Run "semanage permissive -d %s" as root.', self::DOMAIN, trim($output), self::DOMAIN));
        });
        $this->configuration->set(self::CONFIG_KEY, false);
    }

    private function permissive(): bool
    {
        $output = $this->cli->run('semanage permissive -l -n 2>/dev/null');

        return preg_match('/^\s*' . self::DOMAIN . '\s*$/m', $output) === 1;
    }

    private function ensureSemanage(): void
    {
        $missing = false;
        $this->cli->run('command -v semanage', function () use (&$missing) {
            $missing = true;
        });
        if ($missing) {
            $this->pm->ensureInstalled('policycoreutils-python-utils');
        }
    }
}
