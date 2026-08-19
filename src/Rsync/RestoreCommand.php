<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Rsync;

use Vitamin2\Sync\Data\BackupFolder;

/**
 * A local copy of a backup folder back onto the project root, undoing a backed-up pull
 * (see `BackupCommand`, which populates the folder this reads from).
 *
 * A single `rsync` call, not one per recipe path like `BackupCommand`/`RsyncCommand`:
 * `BackupCommand` already recreates each recipe path's own directory structure under the
 * backup folder (via `--relative`), so mirroring that whole folder back onto the project
 * root in one pass restores everything the backup captured, however many recipe paths
 * that was.
 *
 * Doesn't implement `Arrayable`/`Stringable` like `RsyncCommand`/`BackupCommand` do:
 * those feed `sync:list`/`sync:commands`-style preview tables and command strings, but
 * `sync:backups-restore` has no such preview command — its own `--dry` runs a real
 * `rsync --dry-run` for a live preview instead, so only `toArgs()` is ever needed.
 */
final readonly class RestoreCommand
{
    /**
     * Fixed, not user-overridable: `--archive` for a faithful copy (permissions,
     * timestamps, symlinks, ...), `--itemize-changes` so both a dry run and a real
     * restore report exactly what would be (or was) written back.
     */
    private const array OPTIONS = ['--archive', '--itemize-changes'];

    public function __construct(
        public BackupFolder $backup,
        public bool $dry = false,
    ) {}

    /**
     * The backup folder's contents (trailing slash: copy what's inside it, not the
     * folder itself) — already structured as a mirror of the project root by
     * `BackupCommand`'s own `--relative` copy.
     */
    public function origin(): string
    {
        return rtrim($this->backup->path, '/').'/';
    }

    /**
     * The project root (trailing slash: merge into it, not replace it).
     */
    public function target(): string
    {
        return rtrim(base_path(), '/').'/';
    }

    /**
     * Get this command as an argument list, safe to hand directly to a process runner
     * without shell interpretation of paths or options.
     *
     * @return list<string>
     */
    public function toArgs(): array
    {
        return [
            'rsync',
            ...self::OPTIONS,
            ...($this->dry ? ['--dry-run'] : []),
            $this->origin(),
            $this->target(),
        ];
    }
}
