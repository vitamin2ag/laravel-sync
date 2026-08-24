<?php

declare(strict_types=1);

namespace Vitamin2\Sync;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Vitamin2\Sync\Concurrency\SyncLock;
use Vitamin2\Sync\Data\Backup;
use Vitamin2\Sync\Data\BackupFolder;
use Vitamin2\Sync\Data\Recipe;
use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Enums\Operation;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\Rsync\RestoreCommand;
use Vitamin2\Sync\Rsync\RsyncOptions;

class Sync
{
    /**
     * Get all remotes defined in the config.
     *
     * @return Collection<string, Remote>
     */
    public function remotes(): Collection
    {
        return once(function () {
            /** @var array<string, array{user?: string, host?: string, root: string, port?: int, read_only?: bool}> $remotes */
            $remotes = config('sync.remotes', []);

            if ($remotes === []) {
                throw SyncException::noRemotesConfigured();
            }

            return collect($remotes)->map(
                fn (array $config, string $name) => Remote::fromArray($name, $config),
            );
        });
    }

    /**
     * Get all recipes defined in the config.
     *
     * @return Collection<string, Recipe>
     */
    public function recipes(): Collection
    {
        return once(function () {
            /** @var array<string, array<int, string>> $recipes */
            $recipes = config('sync.recipes', []);

            if ($recipes === []) {
                throw SyncException::noRecipesConfigured();
            }

            // Indexed by literal key, not `config("sync.excludes.{$name}")`: a recipe name
            // containing a "." would be misread by `config()`'s dot-notation.
            /** @var array<string, array<int, string>> $excludes */
            $excludes = config('sync.excludes', []);
            /** @var array<string, array<int, string>> $excludesFrom */
            $excludesFrom = config('sync.excludes_from', []);

            return collect($recipes)->map(
                fn (array $paths, string $name) => Recipe::fromArray(
                    $name,
                    $paths,
                    self::filterStrings((array) ($excludes[$name] ?? [])),
                    self::filterStrings((array) ($excludesFrom[$name] ?? [])),
                ),
            );
        });
    }

    /**
     * Get the default rsync options defined in the config.
     *
     * @return array<int, string>
     */
    public function defaultOptions(): array
    {
        return once(fn () => self::filterStrings((array) config('sync.options', [])));
    }

    /**
     * Get the configured backup directory, relative to the project's root.
     */
    public function backupDir(): string
    {
        $value = config('sync.backup_dir', '.sync-backups');

        return is_string($value) ? $value : '.sync-backups';
    }

    /**
     * Start a backup for the given recipes.
     *
     * The single place a `Backup` is created from config, so neither guard can be skipped.
     * Built from `guardBackupDirSafe()`'s dot-collapsed return value, not the raw
     * configured string, so `$backup->dir` matches exactly what was validated.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function startBackup(Collection $recipes): Backup
    {
        $backup = Backup::now($this->guardBackupDirSafe($this->backupDir()));

        $this->guardBackupNotNested($backup, $recipes);

        return $backup;
    }

    /**
     * List the timestamped backup folders on disk under `backup_dir`, newest first.
     *
     * Not memoized with `once()` like `remotes()`/`recipes()`/`defaultOptions()` — this
     * list must reflect a delete made earlier in the same request (`sync:backups-clean`).
     *
     * @return Collection<int, BackupFolder>
     */
    public function backups(): Collection
    {
        // The dot-collapsed directory, not the raw configured string: a ".." through a
        // not-yet-existing intermediate would validate as safe but resolve to nothing here.
        $dir = base_path($this->guardBackupDirSafe($this->backupDir()));

        // `glob()`, not `File::isDirectory()` + `File::directories()`: that check-then-act
        // pair races a concurrent `sync:backups-clean`, and Finder-backed `directories()`
        // throws on a now-missing directory. `$dir` is escaped so a `backup_dir` containing
        // a glob metacharacter can't widen the pattern onto sibling directories.
        $directories = glob($this->escapeGlobPattern($dir).'/*', GLOB_ONLYDIR) ?: [];

        // Rejects symlinks even though `GLOB_ONLYDIR` follows them: a symlinked entry would
        // let `sync:backups-clean` delete contents living outside `backup_dir`.
        return collect($directories)
            ->reject(fn (string $path) => is_link($path))
            ->map(fn (string $path) => BackupFolder::tryFromPath($path, fn () => $this->directorySize($path)))
            ->filter()
            ->sortByDesc(fn (BackupFolder $folder) => $folder->name)
            ->values();
    }

    /**
     * Delete a single backup folder, reporting whether it actually succeeded.
     *
     * Re-checks the folder is still safe to act on immediately before deleting (see
     * `isUnsafeToActOn()`) — without it, `File::deleteDirectory()` would follow a symlink
     * (at the leaf or an ancestor) and delete whatever it now points at instead of a real
     * backup folder.
     *
     * `File::deleteDirectory()`'s own return value isn't trustworthy: it reports success
     * once the top-level directory existed, even when an individual file inside failed to
     * delete (in which case the directory itself survives, non-empty) — checking the
     * directory is actually gone afterward is the only reliable signal.
     */
    public function deleteBackup(BackupFolder $folder): bool
    {
        if ($this->isUnsafeToActOn($folder)) {
            return false;
        }

        File::deleteDirectory($folder->path);

        return ! File::isDirectory($folder->path);
    }

    /**
     * Restore a backup folder's contents back onto the project root.
     *
     * Runs `rsync` directly, not via `PendingSync`: a restore has no remote, recipe, or
     * rsync-option shape to build one of those from, just a single local copy.
     *
     * `isUnsafeToActOn()` is re-checked before *every* command, not once up front: a
     * mirrored restore runs one per top-level entry (see `restoreCommands()`), and each
     * is its own window for the folder to be swapped out from under the restore already
     * in progress — checking only before the first would leave every later one exposed.
     */
    public function restoreBackup(BackupFolder $folder, bool $dry, bool $mirror = false, ?Closure $onOutput = null): bool
    {
        return $this->restoreCommands($folder, $dry, $mirror)
            ->map(fn (RestoreCommand $command) => ! $this->isUnsafeToActOn($folder)
                && Process::forever()->run($command->toArgs(), $onOutput)->successful())
            ->every(fn (bool $successful) => $successful);
    }

    /**
     * One command for the whole backup folder, or, when mirroring, one per its top-level
     * entry — see `RestoreCommand`'s class docblock for why mirroring can't run as a
     * single whole-folder call.
     *
     * @return Collection<int, RestoreCommand>
     */
    private function restoreCommands(BackupFolder $folder, bool $dry, bool $mirror): Collection
    {
        if (! $mirror) {
            return collect([new RestoreCommand($folder, $dry, $mirror)]);
        }

        return collect($folder->topLevelEntries())
            ->map(fn (string $entry) => new RestoreCommand($folder, $dry, $mirror, $entry));
    }

    /**
     * Whether a backup folder is no longer safe to act on, re-checked right before
     * `deleteBackup()`/`restoreBackup()` run — an interactive prompt sits between listing
     * and acting, an arbitrarily long window for the folder to change underneath.
     *
     * `is_link()` catches the leaf itself being swapped for a symlink. The canonical-path
     * comparison catches the other case: `backup_dir` is allowed to be a symlink (see
     * `guardBackupDirSafe()`), so its *ancestor* can be repointed afterward to a different,
     * still-real, same-named directory — one `is_link()` on the leaf can't see.
     */
    private function isUnsafeToActOn(BackupFolder $folder): bool
    {
        if (is_link($folder->path)) {
            return true;
        }

        $normalizedReal = BackupFolder::normalizeRealpath(realpath($folder->path));

        return $normalizedReal === null || $folder->canonicalPath === null || $normalizedReal !== $folder->canonicalPath;
    }

    /**
     * Select backups by retention criteria: older than `$olderThan` days (if given),
     * excluding the `$keep` newest (if given) — so the two combine into the common
     * "delete anything old, but never touch the N most recent" rotation, and `--keep`
     * alone still works as a plain backup-count cap.
     *
     * `$backups` must already be sorted newest-first, as `backups()` returns it.
     *
     * @param  Collection<int, BackupFolder>  $backups
     * @return Collection<int, BackupFolder>
     */
    public function filterByRetention(Collection $backups, ?int $keep, ?int $olderThan): Collection
    {
        $candidates = $backups;

        if ($olderThan !== null) {
            $cutoff = now()->subDays($olderThan);
            $candidates = $candidates->filter(fn (BackupFolder $folder) => $folder->createdAt->lt($cutoff));
        }

        if ($keep !== null) {
            $candidates = $candidates->whereNotIn('name', $backups->take($keep)->pluck('name'), true);
        }

        return $candidates->values();
    }

    /**
     * Guard against a `backup_dir` that would write or delete outside the project.
     *
     * Takes the directory explicitly rather than reading `$this->backupDir()`, since
     * `prepare()` validates a specific `Backup`'s own `dir`.
     *
     * Lexical checks first, so they work before the directory exists: blank, absolute,
     * root-resolving and root-escaping paths are refused. Absolute needs its own check —
     * the segment parser would drop the leading "/" and read "/tmp" as relative "tmp".
     * Then, where the directory or a parent exists, symlinks are resolved and one leading
     * outside the project refused, which no lexical check can catch.
     *
     * Returns the dot-collapsed path it validated, so callers use exactly what was checked.
     */
    public function guardBackupDirSafe(string $dir): string
    {
        $normalized = Recipe::normalizePathSeparators($dir);

        if (self::isAbsolutePath($normalized)) {
            throw SyncException::backupDirUnsafe($dir);
        }

        [$segments, $escaped] = $this->collapseDotSegments($normalized);

        if ($escaped || $segments === []) {
            throw SyncException::backupDirUnsafe($dir);
        }

        $this->guardBackupDirNotEscapingRootOnDisk($dir, $segments);

        return implode('/', $segments);
    }

    /**
     * Lexically collapse "." and ".." segments out of an already-`/`-normalized path,
     * without touching the filesystem. `$escaped` reports a ".." that popped above where
     * collapsing started, which every caller rejects outright.
     *
     * @return array{0: array<int, string>, 1: bool}
     */
    private function collapseDotSegments(string $normalized): array
    {
        $segments = [];
        $escaped = false;

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    $escaped = true;

                    continue;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return [$segments, $escaped];
    }

    /**
     * Resolve the nearest existing ancestor of the (lexically already-safe) backup
     * directory and refuse it unless that symlink-resolved path sits inside the project
     * root. The walk always terminates, since `$ancestor` starts under `base_path()`.
     *
     * `realpath()` is not trusted to succeed just because `file_exists()` did — a real
     * TOCTOU window — so a failure on either side counts as unsafe.
     *
     * Resolving to the project root is refused only when the full directory already
     * exists there: a not-yet-created `backup_dir` legitimately walks up to the root.
     *
     * Compares case-sensitively, deliberately unlike `normalizePath()` elsewhere in this
     * class. This is the one comparison deciding filesystem safety from real disk state,
     * so folding case would let a symlink into a case-differing sibling read as "inside".
     *
     * @param  array<int, string>  $segments
     */
    private function guardBackupDirNotEscapingRootOnDisk(string $dir, array $segments): void
    {
        $target = base_path(implode('/', $segments));
        $ancestor = $target;

        while (! file_exists($ancestor)) {
            $ancestor = dirname($ancestor);
        }

        $normalizedReal = BackupFolder::normalizeRealpath(realpath($ancestor));
        $normalizedRoot = BackupFolder::normalizeRealpath(realpath(base_path()));

        if ($normalizedReal === null || $normalizedRoot === null || ! $this->isPathWithin($normalizedReal, $normalizedRoot)) {
            throw SyncException::backupDirUnsafe($dir);
        }

        if ($ancestor === $target && $normalizedReal === $normalizedRoot) {
            throw SyncException::backupDirUnsafe($dir);
        }
    }

    /**
     * Whether an already-`/`-normalized path is absolute — a leading separator, or a Windows
     * drive-absolute path (`C:/...`). `C:foo` (no separator after the colon) is drive-relative,
     * not absolute, and must not match — otherwise a POSIX relative path like `x:rules` is
     * misread as absolute.
     */
    private static function isAbsolutePath(string $normalized): bool
    {
        return $normalized !== '' && ($normalized[0] === '/' || preg_match('#^[A-Za-z]:/#', $normalized) === 1);
    }

    /**
     * Resolve a configured `excludes_from` entry to the absolute path `rsync` reads, for both
     * the guard and the `--exclude-from=` flag. An absolute entry is taken as written:
     * `base_path()` would rebase it, since `join_paths()` `ltrim()`s the leading separator.
     */
    public static function resolveExcludesFromPath(string $path): string
    {
        return self::isAbsolutePath($path) ? $path : base_path($path);
    }

    /**
     * Sum the size in bytes of every file under a directory, including hidden ones.
     *
     * `scandir()`, not `File::allFiles()`: the latter is Finder-backed and throws if the
     * directory vanishes between `backups()` listing it and this sizing it. Suppressed,
     * `scandir()` never throws, so a vanished folder sizes to 0. It also lists hidden and
     * regular entries in one pass and never treats `$path` as a glob pattern.
     *
     * Never follows a symlinked entry: `rsync --archive` preserves symlinks verbatim, so a
     * backup can contain one pointing at its own ancestor — recursing would loop until the
     * path exceeds the OS length limit.
     */
    private function directorySize(string $path): int
    {
        $size = 0;

        foreach (@scandir($path) ?: [] as $name) {
            if ($name === '.') {
                continue;
            }

            if ($name === '..') {
                continue;
            }

            $entry = "{$path}/{$name}";

            if (is_link($entry)) {
                continue;
            }

            $size += (is_dir($entry) ? $this->directorySize($entry) : @filesize($entry)) ?: 0;
        }

        return $size;
    }

    /**
     * Escape glob metacharacters in a literal path so it can prefix a `glob()` pattern.
     *
     * Wraps each in a single-character bracket expression ("*" becomes "[*]") rather than
     * backslash-escaping, which is ambiguous with the Windows path separator.
     */
    private function escapeGlobPattern(string $path): string
    {
        return preg_replace('/[*?\[\]]/', '[$0]', $path) ?? $path;
    }

    /**
     * Filter a mixed array down to its string values, reindexed.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<int, string>
     */
    public static function filterStrings(array $values): array
    {
        return collect($values)->filter(fn (mixed $value) => is_string($value))->values()->all();
    }

    /**
     * Get a single remote by name.
     */
    public function remote(string $name): Remote
    {
        return $this->remotes()->get($name) ?? throw SyncException::unknownRemote($name);
    }

    /**
     * Get the concurrency guard for a remote.
     *
     * Keyed by the remote's physical target (`host:port` plus `root`, or `root` alone when
     * local), not its config name or SSH `user`: the race guarded is two `rsync` processes
     * writing one path, so aliases of the same directory must contend for one lock.
     *
     * The identity is canonicalized (dot segments, duplicate slashes, case) before hashing,
     * so cosmetic differences still collide. Case-folding over-locks two case-differing paths
     * on a case-sensitive filesystem, preferred to missing a real race on a case-insensitive
     * one. `xxh128` because the arch preset rejects `md5`, though this is only a filename.
     */
    public function lock(Remote $remote): SyncLock
    {
        [$rootSegments] = $this->collapseDotSegments(str_replace('\\', '/', $remote->root));
        $root = '/'.implode('/', $rootSegments);

        $identity = $remote->isLocal()
            ? $root
            : sprintf('%s:%d%s', $remote->host, $remote->port, $root);

        $identity = $this->normalizePath($identity);

        return new SyncLock(storage_path('framework/cache/sync-locks/'.hash('xxh128', $identity).'.lock'));
    }

    /**
     * Get a single recipe by name.
     */
    public function recipe(string $name): Recipe
    {
        return $this->recipes()->get($name) ?? throw SyncException::unknownRecipe($name);
    }

    /**
     * Prepare a guarded sync for the given operation, remote, recipes, and options.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function prepare(Operation $operation, Remote $remote, Collection $recipes, RsyncOptions $options, ?Backup $backup = null): PendingSync
    {
        $this->guardReadOnly($operation, $remote);
        $this->guardRecipePathsAreRelative($recipes);
        $this->guardNotSamePath($remote, $recipes);
        $this->guardExcludesFromFilesExist($recipes);

        if ($backup instanceof Backup && $operation === Operation::Pull) {
            $this->guardBackup($backup, $recipes);
        }

        return new PendingSync($operation, $remote, $recipes, $options, $backup);
    }

    /**
     * Guard against pushing to a read-only remote.
     *
     * Public as well as called by `prepare()`, so a command can fail fast before
     * prompting for recipes and options.
     */
    public function guardReadOnly(Operation $operation, Remote $remote): void
    {
        if ($operation === Operation::Push && $remote->readOnly) {
            throw SyncException::remoteIsReadOnly($remote->name);
        }
    }

    /**
     * Guard against an absolute recipe path. `base_path()` `ltrim()`s a leading separator
     * via `join_paths()`, so an absolute path would otherwise be silently rebased under the
     * project root instead of being used or refused.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function guardRecipePathsAreRelative(Collection $recipes): void
    {
        foreach ($recipes as $recipe) {
            foreach ($recipe->paths as $path) {
                if (self::isAbsolutePath(Recipe::normalizePathSeparators($path))) {
                    throw SyncException::recipePathAbsolute($recipe->name, $path);
                }
            }
        }
    }

    /**
     * Guard against syncing a recipe path with itself (e.g. a "local" remote whose
     * root equals the project's base path).
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function guardNotSamePath(Remote $remote, Collection $recipes): void
    {
        foreach ($this->resolvedPaths($recipes) as $path) {
            $remotePath = $this->normalizePath($remote->path($path));
            $localPath = $this->normalizePath(base_path($path));

            if ($remotePath === $localPath) {
                throw SyncException::samePath($path);
            }
        }
    }

    /**
     * Fail fast on a missing `excludes_from` file, rather than letting `rsync` fail
     * mid-transfer with a rawer error. Only the recipes being synced are checked.
     *
     * Containment is deliberately not enforced: an absolute path, a "..", or a symlink out
     * all resolve as written. `File::isFile()`, not `File::exists()` — the latter also passes
     * for a directory, and for `base_path('')`.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function guardExcludesFromFilesExist(Collection $recipes): void
    {
        foreach ($recipes as $recipe) {
            foreach ($recipe->excludesFrom as $path) {
                if (! File::isFile(self::resolveExcludesFromPath($path))) {
                    throw SyncException::excludesFromFileMissing($recipe->name, $path);
                }
            }
        }
    }

    /**
     * Guard against the backup directory being the same as, or nested inside, a recipe
     * path being backed up — otherwise a pull's backup pass would copy the (growing)
     * backup folder into itself.
     *
     * Compares dot-collapsed, not raw: a ".." segment can land inside a recipe directory
     * that a plain string comparison against the literal wouldn't recognize.
     *
     * Also refuses a `dir` stepping above the project root, so a caller isn't relying on
     * the convention of having run `guardBackupDirSafe()` first.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    public function guardBackupNotNested(Backup $backup, Collection $recipes): void
    {
        [$backupSegments, $escaped] = $this->collapseDotSegments(str_replace('\\', '/', $backup->dir));

        if ($escaped) {
            throw SyncException::backupDirUnsafe($backup->dir);
        }

        $backupPath = $this->normalizePath(rtrim(base_path(implode('/', $backupSegments)), '/'));

        foreach ($this->resolvedPaths($recipes) as $path) {
            $recipePath = $this->normalizePath(rtrim(base_path($path), '/'));

            if ($this->isPathWithin($backupPath, $recipePath)) {
                throw SyncException::backupDirNested($backup->dir, $path);
            }
        }
    }

    /**
     * Run both backup-dir guards for a caller-supplied `Backup`, which — unlike one from
     * `startBackup()` — may carry a dir that was never validated.
     *
     * @param  Collection<int, Recipe>  $recipes
     */
    private function guardBackup(Backup $backup, Collection $recipes): void
    {
        $this->guardBackupDirSafe($backup->dir);
        $this->guardBackupNotNested($backup, $recipes);
    }

    /**
     * Get every recipe path, flattened and de-duplicated.
     *
     * @param  Collection<int, Recipe>  $recipes
     * @return Collection<int, string>
     */
    private function resolvedPaths(Collection $recipes): Collection
    {
        return $recipes->flatMap(fn (Recipe $recipe) => $recipe->paths)->unique();
    }

    /**
     * Normalize a path for comparison. Both sides need it: on Windows a local remote's `root`
     * carries backslashes `Remote::path()` leaves alone. Case is folded because two paths
     * differing only by case are one directory on macOS and Windows.
     */
    private function normalizePath(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }

    /**
     * Whether `$path` is the same as, or nested inside, `$ancestor`. Both must already
     * be normalized (via `normalizePath()`) and trailing-slash-free.
     */
    private function isPathWithin(string $path, string $ancestor): bool
    {
        return str_starts_with($path.'/', $ancestor.'/');
    }
}
