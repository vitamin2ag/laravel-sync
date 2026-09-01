<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Vitamin2\Sync\Commands\Concerns\ConfirmsUnlessSkipped;
use Vitamin2\Sync\Commands\Concerns\ResolvesSyncInput;
use Vitamin2\Sync\Data\Backup;
use Vitamin2\Sync\Data\Recipe;
use Vitamin2\Sync\Enums\Operation;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\PendingSync;
use Vitamin2\Sync\Rsync\RsyncCommand;

use function Laravel\Prompts\confirm;

class SyncCommand extends Command
{
    use ConfirmsUnlessSkipped;
    use ResolvesSyncInput;

    protected $signature = 'sync
        {operation? : The operation to perform (push or pull)}
        {remote? : The remote to sync with}
        {recipe?* : The recipes defining the paths to sync}
        {--O|option=* : Override the default rsync options}
        {--A|all : Sync all recipes}
        {--D|dry : Perform a dry run of the sync}
        {--B|backup : Back up local files before a real pull}';

    protected $description = 'Sync files and folders between environments via rsync';

    public function handle(): int
    {
        if (! ($pending = $this->resolvePendingSync()) instanceof PendingSync) {
            return self::FAILURE;
        }

        $lock = $this->syncService()->lock($pending->remote);

        try {
            if (! $lock->acquire()) {
                throw SyncException::lockUnavailable($pending->remote->name);
            }

            return $this->runPending($pending);
        } catch (SyncException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function runPending(PendingSync $pending): int
    {
        $dry = (bool) $this->option('dry');

        if (! $this->confirmUnlessSkipped($dry, fn () => $this->confirmSync($pending))) {
            $this->comment('Sync aborted.');

            return self::SUCCESS;
        }

        $shouldStreamOutput = $dry || $pending->options->producesOutput();
        $onOutput = $shouldStreamOutput ? fn (string $type, string $output) => $this->output->write($output) : null;
        $onFailure = $shouldStreamOutput ? null : $this->reportProcessFailure(...);

        if ($pending->backup instanceof Backup) {
            $this->comment('Backing up local files...');

            if (! $pending->runBackup($onOutput, $onFailure)) {
                $this->error('Backup failed. Nothing was synced — your local files are untouched.');

                return self::FAILURE;
            }
        }

        $commands = $pending->commands();
        $onSuccess = $commands->count() > 1
            ? fn (RsyncCommand $command) => $this->info("{$command->path} synced successfully.")
            : null;

        if (! $pending->runSync($onOutput, $onFailure, $onSuccess)) {
            $this->error($dry ? 'Dry run failed.' : 'Sync failed.');

            return self::FAILURE;
        }

        $this->info($dry ? 'Dry run completed successfully.' : 'Sync completed successfully.');

        return self::SUCCESS;
    }

    /**
     * `sync` actually runs the backup it confirms, unlike the preview commands.
     */
    protected function promptsForBackupConfirmation(): bool
    {
        return true;
    }

    /**
     * Only wired in when output wasn't already streamed live — otherwise the process's
     * own error output already reached the terminal and repeating it here would be noise.
     */
    private function reportProcessFailure(ProcessResult $result): void
    {
        $output = ($errorOutput = trim($result->errorOutput())) !== '' ? $errorOutput : trim($result->output());

        if ($output !== '') {
            $this->line($output);
        }
    }

    private function confirmSync(PendingSync $pending): bool
    {
        $names = $pending->recipes->map(fn (Recipe $recipe) => $recipe->name)->implode(' and ');
        $preposition = $pending->operation === Operation::Push ? 'to' : 'from';

        return confirm(
            label: sprintf(
                'You are about to %s "%s" %s "%s". Are you sure?',
                $pending->operation->value,
                $names,
                $preposition,
                $pending->remote->name,
            ),
            default: false,
        );
    }
}
