<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Vitamin2\Sync\Commands\Concerns\ConfirmsUnlessSkipped;
use Vitamin2\Sync\Data\BackupFolder;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\Sync;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Doesn't use `ResolvesSyncInput` (or `ResolvesRemote`) — it resolves no operation,
 * remote, recipes, or rsync options, just a backup, so neither trait's shape applies.
 */
class SyncBackupsRestoreCommand extends Command
{
    use ConfirmsUnlessSkipped;

    /**
     * The command signature.
     */
    protected $signature = 'sync:backups-restore
        {backup? : The backup to restore}
        {--D|dry : Preview what would be restored, without restoring anything}
        {--F|force : Skip the confirmation prompt}';

    /**
     * The command description.
     */
    protected $description = 'Restore a backup folder\'s contents back onto the project root';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sync = resolve(Sync::class);

        try {
            $backups = $sync->backups();

            // Only short-circuits when no backup was explicitly named: with a `backup`
            // argument given, an empty list must still reach resolveBackup() below so it
            // reports "unknown backup" — reporting "no backups found" (and exiting 0)
            // instead would let e.g. `sync:backups-restore missing --no-interaction`
            // silently "succeed" against a typo'd or already-deleted backup name.
            if ($backups->isEmpty() && ! $this->hasExplicitBackupArgument()) {
                $this->info(sprintf('No backups found in "%s".', $sync->backupDir()));

                return self::SUCCESS;
            }

            $backup = $this->resolveBackup($backups);
        } catch (SyncException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $force = (bool) $this->option('force');

        if (! $this->confirmUnlessSkipped($dry || $force, fn () => $this->confirmRestore($backup))) {
            $this->comment('Restore aborted.');

            return self::SUCCESS;
        }

        $onOutput = fn (string $type, string $output) => $this->output->write($output);

        if (! $sync->restoreBackup($backup, $dry, $onOutput)) {
            $this->error($dry ? 'Dry run failed.' : 'Restore failed.');

            return self::FAILURE;
        }

        $this->info($dry
            ? 'Dry run completed successfully. Nothing was restored.'
            : sprintf('Restored backup "%s" onto the project root.', $backup->name));

        return self::SUCCESS;
    }

    /**
     * Whether the `backup` argument was given as a non-empty string — an explicit,
     * validatable request, as opposed to it being omitted (which `handle()` treats as
     * "let me pick from what's available" rather than "I asked for a specific backup").
     */
    private function hasExplicitBackupArgument(): bool
    {
        $name = $this->argument('backup');

        return is_string($name) && $name !== '';
    }

    /**
     * Resolve which backup to restore: the `backup` argument if given, an interactive
     * `select()` otherwise, or a friendly error when neither applies — matching
     * `ResolvesRemote::resolveArgumentOrPrompt()`'s exact shape (prompt only when the
     * argument is missing outright, not merely blank; an explicit empty string always
     * fails fast with `backupRequired()`, interactive or not), even though this command
     * doesn't use that trait itself (see the class docblock).
     *
     * The chosen name is looked up once, at the end, in the already-fetched `$backups`
     * collection — not via a second call to `backups()`, which is deliberately not
     * memoized and would re-glob `backup_dir` and recompute every backup folder's
     * recursive size all over again just to find an entry `handle()` already has.
     *
     * @param  Collection<int, BackupFolder>  $backups
     */
    private function resolveBackup(Collection $backups): BackupFolder
    {
        $name = $this->argument('backup');

        if (! is_string($name) && $this->input->isInteractive()) {
            $name = select(
                label: 'Which backup do you want to restore?',
                options: $backups->mapWithKeys(fn (BackupFolder $folder) => [$folder->name => $folder->label()])->all(),
            );
        }

        if (! is_string($name) || $name === '') {
            throw SyncException::backupRequired();
        }

        return $backups->firstWhere('name', $name) ?? throw SyncException::unknownBackup($name);
    }

    private function confirmRestore(BackupFolder $backup): bool
    {
        return confirm(
            label: sprintf(
                'You are about to restore backup "%s" onto your project. Existing files may be overwritten. Are you sure?',
                $backup->name,
            ),
            default: false,
        );
    }
}
