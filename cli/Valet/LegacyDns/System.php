<?php

namespace Valet\LegacyDns;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Narrow access to the files, units and packages that the legacy DNS
 * migration reads or changes.
 *
 * Paths are always absolute system paths. A root prefix lets tests operate on
 * a scratch directory instead of the real system.
 */
class System
{
    /**
     * @var callable(string): array{int, string}
     */
    private $runner;

    /**
     * @param (callable(string): array{int, string})|null $runner
     */
    public function __construct(private string $root = '', ?callable $runner = null)
    {
        $this->root = rtrim($root, '/');
        $this->runner = $runner ?? static function (string $command): array {
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(120)->run();

            return [(int) $process->getExitCode(), $process->getOutput() . $process->getErrorOutput()];
        };
    }

    public function path(string $path): string
    {
        return $this->root . $path;
    }

    /**
     * Describe a path without following a final symlink.
     *
     * @return array{type: string, mode: int, uid: int, gid: int, target: ?string, content: ?string,
     *     immutable: bool, mtime: int, ctime: int}|null
     */
    public function node(string $path): ?array
    {
        $real = $this->path($path);
        clearstatcache(true, $real);
        $stat = @lstat($real);
        if ($stat === false) {
            return null;
        }

        $type = match ($stat['mode'] & 0170000) {
            0120000 => 'link',
            0100000 => 'file',
            0040000 => 'dir',
            default => 'other',
        };
        $content = null;
        if ($type === 'file' && $stat['size'] <= 1048576) {
            $content = (string) @file_get_contents($real);
        }

        return [
            'type' => $type,
            'mode' => $stat['mode'] & 07777,
            'uid' => $stat['uid'],
            'gid' => $stat['gid'],
            'target' => $type === 'link' ? (string) readlink($real) : null,
            'content' => $content,
            'immutable' => $type === 'file' && $this->immutable($path),
            'mtime' => $stat['mtime'],
            'ctime' => $stat['ctime'],
        ];
    }

    /**
     * @return list<string>
     */
    public function listDir(string $path): array
    {
        $entries = @scandir($this->path($path));
        if ($entries === false) {
            return [];
        }

        return array_values(array_filter($entries, fn ($entry) => $entry !== '.' && $entry !== '..'));
    }

    /**
     * Resolve a symlink chain to an absolute system path without requiring
     * the final target to exist.
     */
    public function resolveLink(string $path, int $depth = 0): string
    {
        $node = $this->node($path);
        if ($node === null || $node['type'] !== 'link' || $depth > 16) {
            return $path;
        }

        return $this->resolveLink(self::absoluteTarget($path, (string) $node['target']), $depth + 1);
    }

    public static function absoluteTarget(string $link, string $target): string
    {
        $path = str_starts_with($target, '/') ? $target : dirname($link) . '/' . $target;
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    public function immutable(string $path): bool
    {
        [$status, $output] = $this->run('lsattr -d ' . escapeshellarg($this->path($path)) . ' 2>/dev/null');
        if ($status !== 0 || !preg_match('/^(\S+)\s/', $output, $matches)) {
            return false;
        }

        return str_contains($matches[1], 'i');
    }

    /**
     * @return array{int, string}
     */
    public function run(string $command): array
    {
        return ($this->runner)($command);
    }

    public function mustRun(string $command): string
    {
        [$status, $output] = $this->run($command);
        if ($status !== 0) {
            throw new RuntimeException(sprintf('Command failed (%d): %s%s%s', $status, $command, PHP_EOL, trim($output)));
        }

        return $output;
    }

    /**
     * @return array{load: string, active: string, enabled: string, preset: string}
     */
    public function unit(string $unit): array
    {
        [, $output] = $this->run('systemctl show --no-pager ' . escapeshellarg($unit)
            . ' -p LoadState -p ActiveState -p UnitFileState -p UnitFilePreset 2>/dev/null');
        $values = ['LoadState' => '', 'ActiveState' => '', 'UnitFileState' => '', 'UnitFilePreset' => ''];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $pair = explode('=', $line, 2);
            if (count($pair) === 2 && array_key_exists($pair[0], $values)) {
                $values[$pair[0]] = trim($pair[1]);
            }
        }

        return [
            'load' => $values['LoadState'] ?: 'not-found',
            'active' => $values['ActiveState'] ?: 'inactive',
            'enabled' => $values['UnitFileState'],
            'preset' => $values['UnitFilePreset'],
        ];
    }

    public function systemdVersion(): int
    {
        [$status, $output] = $this->run('systemctl --version 2>/dev/null');

        return $status === 0 && preg_match('/^systemd\s+(\d+)/', $output, $matches) ? (int) $matches[1] : 0;
    }

    /**
     * Find the package that owns a path and the digest the package recorded
     * for it. Supports dpkg and rpm; other package managers report unknown.
     *
     * @return array{package: string, algorithm: ?string, digest: ?string}|null
     */
    public function packageFile(string $path): ?array
    {
        [$status, $output] = $this->run('dpkg-query -S ' . escapeshellarg($path) . ' 2>/dev/null');
        if ($status === 0 && preg_match('/^([^:\s,]+)(?::\S+)?: ' . preg_quote($path, '/') . '$/m', $output, $matches)) {
            $package = $matches[1];
            [, $conffiles] = $this->run('dpkg-query -W -f=\'${Conffiles}\n\' ' . escapeshellarg($package) . ' 2>/dev/null');
            $digest = null;
            if (preg_match('/^\s*' . preg_quote($path, '/') . '\s+([0-9a-f]{32})(?:\s|$)/m', $conffiles, $hash)) {
                $digest = $hash[1];
            }

            return ['package' => $package, 'algorithm' => $digest === null ? null : 'md5', 'digest' => $digest];
        }

        [$status, $output] = $this->run('rpm -qf --qf \'%{NAME}\n\' ' . escapeshellarg($path) . ' 2>/dev/null');
        if ($status === 0 && preg_match('/^([A-Za-z0-9._+-]+)$/m', trim($output), $matches)) {
            $package = $matches[1];
            [, $list] = $this->run('rpm -q --qf \'[%{FILENAMES} %{FILEDIGESTS}\n]\' ' . escapeshellarg($package) . ' 2>/dev/null');
            $digest = null;
            if (preg_match('/^' . preg_quote($path, '/') . ' ([0-9a-f]{32,128})$/m', $list, $hash)) {
                $digest = $hash[1];
            }
            $algorithm = match (strlen((string) $digest)) {
                32 => 'md5',
                64 => 'sha256',
                128 => 'sha512',
                default => null,
            };

            return ['package' => $package, 'algorithm' => $algorithm, 'digest' => $algorithm === null ? null : $digest];
        }

        return null;
    }

    /**
     * Whether a UDP and a TCP socket are bound to an IPv4 address and port,
     * from /proc/net. Null when the kernel tables are unavailable.
     */
    public function listening(string $address, int $port): ?bool
    {
        $octets = array_reverse(explode('.', $address));
        $needle = strtoupper(vsprintf('%02x%02x%02x%02x', array_map('intval', $octets))) . ':' . sprintf('%04X', $port);
        foreach (['/proc/net/udp', '/proc/net/tcp'] as $table) {
            $content = @file_get_contents($this->path($table));
            if ($content === false) {
                return null;
            }
            if (!preg_match('/^\s*\d+:\s+' . preg_quote($needle, '/') . '\s/mi', $content)) {
                return false;
            }
        }

        return true;
    }

    public function writeFile(string $path, string $content, int $mode, int $uid, int $gid): void
    {
        $real = $this->path($path);
        $temporary = dirname($real) . '/.valet-migration-' . bin2hex(random_bytes(6));
        if (@file_put_contents($temporary, $content) !== strlen($content)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to write ' . $path);
        }
        try {
            $this->applyOwnership($temporary, $mode, $uid, $gid);
        } catch (RuntimeException $exception) {
            @unlink($temporary);
            throw $exception;
        }
        if (!@rename($temporary, $real)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to replace ' . $path);
        }
    }

    public function symlink(string $path, string $target, int $uid, int $gid): void
    {
        $real = $this->path($path);
        $temporary = dirname($real) . '/.valet-migration-' . bin2hex(random_bytes(6));
        if (!@symlink($target, $temporary)) {
            throw new RuntimeException('Unable to create a symlink for ' . $path);
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @lchown($temporary, $uid);
            @lchgrp($temporary, $gid);
        }
        if (!@rename($temporary, $real)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to replace ' . $path);
        }
    }

    public function mkdir(string $path, int $mode, int $uid, int $gid): void
    {
        $real = $this->path($path);
        if (!is_dir($real) && !@mkdir($real, $mode)) {
            throw new RuntimeException('Unable to create ' . $path);
        }
        $this->applyOwnership($real, $mode, $uid, $gid);
    }

    public function remove(string $path): void
    {
        $real = $this->path($path);
        if (is_dir($real) && !is_link($real)) {
            if (!@rmdir($real)) {
                throw new RuntimeException('Unable to remove directory ' . $path);
            }

            return;
        }
        if ((file_exists($real) || is_link($real)) && !@unlink($real)) {
            throw new RuntimeException('Unable to remove ' . $path);
        }
    }

    public function rename(string $from, string $to): void
    {
        if (!@rename($this->path($from), $this->path($to))) {
            throw new RuntimeException(sprintf('Unable to move %s to %s', $from, $to));
        }
    }

    public function setImmutable(string $path, bool $immutable): void
    {
        $this->mustRun('chattr ' . ($immutable ? '+i' : '-i') . ' ' . escapeshellarg($this->path($path)));
    }

    private function applyOwnership(string $real, int $mode, int $uid, int $gid): void
    {
        @chmod($real, $mode);
        $stat = @stat($real);
        if ($stat !== false && ($stat['uid'] !== $uid || $stat['gid'] !== $gid)) {
            if (!@chown($real, $uid) || !@chgrp($real, $gid)) {
                throw new RuntimeException('Unable to restore ownership of ' . $real);
            }
        }
    }
}
