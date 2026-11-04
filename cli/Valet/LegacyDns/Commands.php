<?php

namespace Valet\LegacyDns;

use ConsoleComponents\Writer;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console glue for "valet dns:migrate" and "valet dns:rollback".
 */
class Commands
{
    /**
     * @param callable(string): void $backend Configures Valet resolution for a domain
     */
    public function __construct(
        private System $system,
        private ?string $domain,
        private $backend
    ) {
    }

    /**
     * @param array<string, string> $choices
     */
    public function plan(array $choices = []): Plan
    {
        $facts = (new Inspector($this->system))->inspect($this->domain);

        return (new Planner())->plan($facts, $choices);
    }

    /**
     * @param array<string, string> $choices
     * @param list<string>          $probes
     */
    public function migrate(OutputInterface $output, bool $dryRun, bool $json, bool $yes, array $choices, array $probes): int
    {
        $plan = $this->plan($choices);
        if ($json) {
            $output->writeln((string) json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($plan->describe() as $line) {
                $output->writeln($line, OutputInterface::OUTPUT_RAW);
            }
        }

        if ($dryRun || !$plan->legacy() || $plan->steps === []) {
            return $plan->blocked() ? 2 : 0;
        }
        if ($plan->blocked()) {
            $output->writeln('');
            $output->writeln('Nothing was changed. Resolve the conflicts or pass the required --decision options, then run the preview again.');

            return 2;
        }
        $this->requireRoot();
        if (!$yes && !Writer::confirm('Apply these changes?', false)) {
            $output->writeln('Nothing was changed.');

            return 1;
        }

        $result = $this->migrator($output)->apply($plan, array_values(array_unique(array_merge(Verifier::DEFAULT_PROBES, $probes))));

        return match ($result['status']) {
            'completed' => 0,
            'rolled-back' => 3,
            default => 4,
        };
    }

    public function rollback(OutputInterface $output, ?string $id, bool $yes, bool $list): int
    {
        $journal = new Journal($this->system);
        if ($list) {
            foreach ($journal->ids() as $entry) {
                $output->writeln(sprintf('%s  %s', $entry, $journal->load($entry)['status']));
            }

            return 0;
        }
        $this->requireRoot();
        if (!$yes && !Writer::confirm('Restore the DNS configuration recorded before ' . ($id ?? 'the latest migration') . '?', false)) {
            return 1;
        }
        $result = $this->migrator($output)->rollback($id);

        return $result['status'] === 'rolled-back' ? 0 : 4;
    }

    /**
     * Warn when legacy DNS changes are present. Never changes anything.
     */
    public function warnIfLegacy(string $context): void
    {
        try {
            $plan = $this->plan();
        } catch (\Throwable) {
            return;
        }
        if (!$plan->legacy()) {
            return;
        }
        Writer::warn(sprintf(
            'DNS changes from an older Valet Linux+ release are still active (%s). %s does not modify them.',
            $plan->generation,
            $context
        ));
        Writer::warn('They can break name resolution, including package downloads. Preview the migration with '
            . '"valet dns:migrate --dry-run" and apply it with "valet dns:migrate"'
            . ($context === 'valet install' ? ', then run "valet install" again.' : '.'));
    }

    private function migrator(OutputInterface $output): Migrator
    {
        return new Migrator(
            $this->system,
            new Journal($this->system),
            new Verifier($this->system),
            $this->backend,
            fn (string $message) => $output->writeln($message, OutputInterface::OUTPUT_RAW)
        );
    }

    private function requireRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            throw new RuntimeException('This command must run as root.');
        }
    }
}
