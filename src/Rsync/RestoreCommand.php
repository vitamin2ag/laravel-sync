<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Rsync;

use Vitamin2\Sync\Data\BackupFolder;

/**
 * A local copy of a backup folder (or one of its top-level entries) back onto the
 * project root, undoing a backed-up pull (see `BackupCommand`, which populates the
 * folder this reads from).
 *
 * One `rsync` call per top-level entry when `$mirror` is set, not one per recipe path
 * like `BackupCommand`/`RsyncCommand`: mirroring adds `--delete`, and `rsync --delete`
 * prunes anything at the *target* missing from the *source* — a single whole-folder call
 * would prune the entire project root down to whatever the backup happened to capture
 * (`vendor/`, `.git/`, unrelated recipes, all of it), since the backup folder's own top
 * level rarely has more than the handful of paths that were actually backed up. Scoping
 * each call to one entry (`$entry`) keeps `--delete` confined to that entry's own subtree.
 * A non-mirroring restore has no such risk and still runs as a single whole-folder call.
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
     * restore report exactly what would be (or was) written back. `--delete` is the one
     * exception, gated behind `$mirror` rather than hardcoded here.
     */
    private const array OPTIONS = ['--archive', '--itemize-changes'];

    public function __construct(
        public BackupFolder $backup,
        public bool $dry = false,
        public bool $mirror = false,
        public ?string $entry = null,
    ) {}

    /**
     * The backup source for this call: the whole folder (always a directory — trailing
     * slash unconditional, as before), or, scoped to one of its top-level entries (see
     * the class docblock), just that entry — trailing slash only when the entry itself
     * is a directory, so a mirrored entry merges into the target instead of nesting
     * under it; a file entry is copied as-is.
     */
    public function origin(): string
    {
        if ($this->entry === null) {
            return rtrim($this->backup->path, '/').'/';
        }

        $path = rtrim("{$this->backup->path}/{$this->entry}", '/');

        return is_dir($path) ? "{$path}/" : $path;
    }

    /**
     * The restore target: the project root (always a directory), or, scoped, the
     * matching path under it — directory-ness is read from the *source* entry, not this
     * path, since the target may not exist yet (e.g. a fresh checkout missing it).
     */
    public function target(): string
    {
        if ($this->entry === null) {
            return rtrim(base_path(), '/').'/';
        }

        $path = rtrim("{$this->backup->path}/{$this->entry}", '/');

        return is_dir($path) ? rtrim(base_path($this->entry), '/').'/' : base_path($this->entry);
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
            ...($this->mirror ? ['--delete'] : []),
            ...($this->dry ? ['--dry-run'] : []),
            $this->origin(),
            $this->target(),
        ];
    }
}
