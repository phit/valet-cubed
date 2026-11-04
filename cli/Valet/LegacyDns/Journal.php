<?php

namespace Valet\LegacyDns;

use RuntimeException;

/**
 * Transaction journal for DNS migrations.
 *
 * Root replays the journal during rollback, so it lives in a root-owned
 * 0700 directory and is rejected if its ownership or mode was changed.
 */
class Journal
{
    public const BASE = '/var/lib/valet-cubed';
    public const DIR = self::BASE . '/dns-migrations';

    /**
     * @var resource|null
     */
    private $lock = null;

    public function __construct(private System $system, private ?int $owner = null)
    {
        $this->owner ??= function_exists('posix_geteuid') ? posix_geteuid() : 0;
    }

    /**
     * Take an exclusive lock so two migrations never run concurrently.
     */
    public function lock(): void
    {
        $this->ensureDirectories();
        $handle = fopen($this->system->path(self::DIR . '/.lock'), 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another DNS migration or rollback is running.');
        }
        $this->lock = $handle;
    }

    public function unlock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /**
     * @param array<string, mixed> $journal
     */
    public function create(array $journal): string
    {
        $this->ensureDirectories();
        $id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
        $journal['id'] = $id;
        $this->save($journal);

        return $id;
    }

    /**
     * @param array<string, mixed> $journal
     */
    public function save(array $journal): void
    {
        $json = json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = $this->file((string) $journal['id']);
        $this->system->writeFile($path, $json . "\n", 0600, (int) $this->owner, $this->group());
        $this->sync($path);
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $id): array
    {
        if (!preg_match('/\A[0-9TZ]+-[0-9a-f]{8}\z/', $id)) {
            throw new RuntimeException('Invalid migration id.');
        }
        $this->assertSecure(self::BASE);
        $this->assertSecure(self::DIR);
        $path = $this->file($id);
        $this->assertSecure($path);
        $journal = json_decode((string) file_get_contents($this->system->path($path)), true);
        if (!is_array($journal) || ($journal['id'] ?? null) !== $id) {
            throw new RuntimeException('The migration journal is unreadable: ' . $path);
        }

        return $journal;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        if ($this->system->node(self::DIR) === null) {
            return [];
        }
        $ids = [];
        foreach ($this->system->listDir(self::DIR) as $entry) {
            if (preg_match('/\A([0-9TZ]+-[0-9a-f]{8})\.json\z/', $entry, $matches)) {
                $ids[] = $matches[1];
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * The newest journal, if any.
     *
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        $ids = $this->ids();

        return $ids === [] ? null : $this->load((string) end($ids));
    }

    public function file(string $id): string
    {
        return self::DIR . '/' . $id . '.json';
    }

    private function ensureDirectories(): void
    {
        foreach ([self::BASE, self::DIR] as $dir) {
            if ($this->system->node($dir) === null) {
                $this->system->mkdir($dir, 0700, (int) $this->owner, $this->group());
            }
            $this->assertSecure($dir);
        }
    }

    private function assertSecure(string $path): void
    {
        $node = $this->system->node($path);
        if ($node === null) {
            throw new RuntimeException('Missing migration state: ' . $path);
        }
        if (!in_array($node['type'], ['dir', 'file'], true) || $node['uid'] !== $this->owner || ($node['mode'] & 0077) !== 0) {
            throw new RuntimeException(sprintf(
                'Refusing to use %s: it must be a %s owned by uid %d with no group or other permissions.',
                $path,
                $node['type'] === 'dir' ? 'directory' : 'regular file',
                $this->owner
            ));
        }
    }

    private function group(): int
    {
        return function_exists('posix_getegid') ? posix_getegid() : 0;
    }

    private function sync(string $path): void
    {
        $handle = @fopen($this->system->path($path), 'r');
        if ($handle !== false) {
            @fsync($handle);
            fclose($handle);
        }
    }
}
