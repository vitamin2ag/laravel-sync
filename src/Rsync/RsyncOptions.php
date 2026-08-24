<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Rsync;

use Illuminate\Support\Collection;
use Stringable;

final readonly class RsyncOptions implements Stringable
{
    /**
     * Rsync flags known to the package, with a human-readable label for prompts.
     *
     * `--dry-run` is deliberately absent: `resolve()` adds it from the command's `-D|--dry`
     * flag, so it must not also be selectable here.
     */
    public const array AVAILABLE = [
        '--archive' => 'Archive mode (preserves permissions, timestamps, symlinks, ...)',
        '--delete' => 'Delete files on the target that no longer exist on the source',
        '--verbose' => 'Increase verbosity',
        '--progress' => 'Show progress during transfer',
        '--compress' => 'Compress file data during the transfer',
        '--stats' => 'Show file transfer statistics',
        '--human-readable' => 'Output numbers in a human-readable format',
        '--itemize-changes' => 'Output a change-summary for all updates',
        '--update' => 'Skip files newer on the target',
        '--partial' => 'Keep partially transferred files',
        '--delete-after' => 'Delete files on the target after the transfer',
        '--checksum' => 'Skip based on checksum, not modification time & size',
        '--copy-links' => 'Transform symlinks into the referent file/dir',
        '--no-perms' => 'Do not preserve permissions',
        '--no-owner' => 'Do not preserve owner',
        '--no-group' => 'Do not preserve group',
        '--backup' => 'Make backups (rsync --backup)',
    ];

    /**
     * Rsync flags that produce visible output while the sync runs.
     */
    public const array OUTPUT_PRODUCING = [
        '--verbose',
        '--progress',
        '--stats',
        '--itemize-changes',
        '--human-readable',
    ];

    /**
     * @param  array<int, string>  $flags
     */
    public function __construct(
        public array $flags,
    ) {}

    /**
     * Resolve the effective rsync options, adding the flags implied by a dry or verbose run.
     *
     * When `$backup` is true, rsync's own backup flags are stripped: they'd conflict with
     * the package's full-copy backup pass.
     *
     * @param  array<int, string>  $flags
     */
    public static function resolve(array $flags, bool $dry, bool $verbose, bool $backup = false): self
    {
        $resolved = collect($flags);

        if ($dry) {
            $resolved = $resolved->merge(['--dry-run', ...self::OUTPUT_PRODUCING]);
        }

        if ($verbose) {
            $resolved = $resolved->merge(self::OUTPUT_PRODUCING);
        }

        if ($backup) {
            $resolved = self::stripBackupFlags($resolved);
        }

        return new self($resolved->filter(fn (string $flag) => $flag !== '')->unique()->values()->all());
    }

    /**
     * Strip rsync's own backup flags: `--backup`, `--backup-dir=DIR`, the two-token
     * `--backup-dir DIR`, and `-b` (including bundled into a cluster like `-ab`).
     *
     * Bare `--backup-dir` consumes the next token as its value, mirroring rsync's own
     * argument parsing, even when that token looks like another flag.
     *
     * @param  Collection<int, string>  $flags
     * @return Collection<int, string>
     */
    private static function stripBackupFlags(Collection $flags): Collection
    {
        $stripped = [];
        $skipNext = false;

        foreach ($flags->values() as $flag) {
            if ($skipNext) {
                $skipNext = false;

                continue;
            }

            if ($flag === '--backup') {
                continue;
            }

            if (str_starts_with($flag, '--backup-dir=')) {
                continue;
            }

            if ($flag === '--backup-dir') {
                $skipNext = true;

                continue;
            }

            if (($flag = self::stripShortBackupFlag($flag)) !== null) {
                $stripped[] = $flag;
            }
        }

        return collect($stripped);
    }

    /**
     * Strip rsync's short `-b` backup flag from a short-option cluster (e.g. `-ab` becomes
     * `-a`), returning null when `-b` was the only option in it.
     */
    private static function stripShortBackupFlag(string $flag): ?string
    {
        if (str_starts_with($flag, '--') || ! str_starts_with($flag, '-') || ! str_contains($flag, 'b')) {
            return $flag;
        }

        $stripped = str_replace('b', '', $flag);

        return $stripped === '-' ? null : $stripped;
    }

    /**
     * Whether any of the resolved flags produce visible output while syncing.
     *
     * Flags outside `AVAILABLE` are assumed to produce output rather than silently suppressing
     * streaming; `--exclude=PATTERN` and `--exclude-from=FILE` are exempted because their
     * user-defined values keep them out of `AVAILABLE` even though they're known to be silent.
     */
    public function producesOutput(): bool
    {
        foreach ($this->flags as $flag) {
            if (str_starts_with($flag, '--exclude=')) {
                continue;
            }

            if (str_starts_with($flag, '--exclude-from=')) {
                continue;
            }

            if (! array_key_exists($flag, self::AVAILABLE) || in_array($flag, self::OUTPUT_PRODUCING, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a copy of these options with a `--exclude=PATTERN` flag appended per pattern,
     * layering one recipe path's excludes onto the sync's shared options without affecting
     * any other path.
     *
     * @param  array<int, string>  $excludes
     */
    public function withExcludes(array $excludes): self
    {
        return $this->withFlags(array_map(fn (string $pattern) => "--exclude={$pattern}", $excludes));
    }

    /**
     * Return a copy of these options with a `--exclude-from=FILE` flag appended per file.
     * Paths must already be absolute — `Sync::resolveExcludesFromPath()` owns that rule, and
     * `rsync` reads an exclude-from file locally however the sync's working directory is set.
     *
     * @param  array<int, string>  $paths
     */
    public function withExcludeFrom(array $paths): self
    {
        return $this->withFlags(array_map(fn (string $path) => "--exclude-from={$path}", $paths));
    }

    /**
     * Return a copy of these options with `$flags` appended. Returns `$this` itself when
     * `$flags` is empty, not a value-identical copy — callers detect the no-op by identity.
     *
     * @param  array<int, string>  $flags
     */
    private function withFlags(array $flags): self
    {
        return $flags === [] ? $this : new self([...$this->flags, ...$flags]);
    }

    public function __toString(): string
    {
        return implode(' ', $this->flags);
    }
}
