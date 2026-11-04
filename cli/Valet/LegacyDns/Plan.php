<?php

namespace Valet\LegacyDns;

/**
 * A reviewable description of what a legacy DNS migration would change.
 */
class Plan
{
    /**
     * @var list<string>
     */
    public array $markers = [];

    /**
     * @var list<string>
     */
    public array $evidence = [];

    /**
     * @var list<string>
     */
    public array $warnings = [];

    /**
     * Problems that prevent an automatic migration.
     *
     * @var list<string>
     */
    public array $conflicts = [];

    /**
     * Follow-up steps the migration cannot do safely by itself.
     *
     * @var list<string>
     */
    public array $manual = [];

    /**
     * @var array<string, array{question: string, options: list<string>, default: ?string, chosen: ?string,
     *     evidence: string}>
     */
    public array $decisions = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $steps = [];

    public string $generation = 'none';

    /**
     * The resolver expected to serve normal DNS after migration.
     */
    public string $resolver = 'unchanged';

    public function legacy(): bool
    {
        return $this->generation !== 'none';
    }

    /**
     * @return list<string>
     */
    public function unresolvedDecisions(): array
    {
        $open = [];
        foreach ($this->decisions as $key => $decision) {
            if ($decision['chosen'] === null) {
                $open[] = $key;
            }
        }

        return $open;
    }

    public function blocked(): bool
    {
        return $this->conflicts !== [] || $this->unresolvedDecisions() !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation,
            'resolver' => $this->resolver,
            'markers' => $this->markers,
            'evidence' => $this->evidence,
            'warnings' => $this->warnings,
            'conflicts' => $this->conflicts,
            'manual' => $this->manual,
            'decisions' => $this->decisions,
            'steps' => array_map(static function (array $step) {
                unset($step['content']);

                return $step;
            }, $this->steps),
            'blocked' => $this->blocked(),
        ];
    }

    /**
     * Human-readable preview.
     *
     * @return list<string>
     */
    public function describe(): array
    {
        $lines = [];
        $lines[] = $this->legacy()
            ? sprintf('Legacy Valet DNS integration detected (%s).', $this->generation)
            : 'No legacy Valet DNS integration detected.';
        foreach (['Evidence' => $this->evidence, 'Warnings' => $this->warnings,
            'Conflicts (migration blocked)' => $this->conflicts, 'Manual follow-up' => $this->manual] as $title => $items) {
            if ($items === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = $title . ':';
            foreach ($items as $item) {
                $lines[] = '  - ' . $item;
            }
        }
        if ($this->decisions !== []) {
            $lines[] = '';
            $lines[] = 'Decisions:';
            foreach ($this->decisions as $key => $decision) {
                $lines[] = sprintf(
                    '  - --%s=%s: %s (%s)',
                    $key,
                    $decision['chosen'] ?? '<required: ' . implode('|', $decision['options']) . '>',
                    $decision['question'],
                    $decision['evidence']
                );
            }
        }
        if ($this->steps !== []) {
            $lines[] = '';
            $lines[] = 'Planned changes:';
            foreach ($this->steps as $index => $step) {
                $lines[] = sprintf('  %2d. %s', $index + 1, self::stepSummary($step));
            }
            $lines[] = '';
            $lines[] = 'Resolver after migration: ' . $this->resolver;
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $step
     */
    public static function stepSummary(array $step): string
    {
        $summary = match ($step['op']) {
            'file-remove' => 'Remove ' . $step['path'],
            'file-restore' => sprintf('Restore %s from %s', $step['to'], $step['from']),
            'file-write' => 'Rewrite ' . $step['path'],
            'symlink' => sprintf('Point %s to %s', $step['path'], $step['target']),
            'immutable' => 'Clear the immutable flag on ' . $step['path'],
            'dir-remove' => 'Remove empty directory ' . $step['path'],
            'unit' => trim(sprintf(
                '%s %s %s',
                $step['enable'] === null ? '' : ($step['enable'] ? 'Enable' : 'Disable'),
                $step['action'] === null ? '' : ($step['enable'] === null ? ucfirst($step['action']) : 'and ' . $step['action']),
                $step['unit']
            )),
            'daemon-reload' => 'Reload systemd unit files',
            'command' => 'Run ' . $step['run'],
            'package-file' => sprintf('Restore %s from package %s', $step['path'], $step['package']),
            'valet-backend' => sprintf('Configure Valet resolution for .%s (delegation or /etc/hosts)', $step['domain']),
            default => (string) $step['op'],
        };

        return isset($step['reason']) ? $summary . ' — ' . $step['reason'] : $summary;
    }
}
