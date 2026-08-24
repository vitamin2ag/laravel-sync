<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Output\OutputInterface;
use ValueError;
use Vitamin2\Sync\Data\Backup;
use Vitamin2\Sync\Data\Recipe;
use Vitamin2\Sync\Enums\Operation;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\PendingSync;
use Vitamin2\Sync\Rsync\RsyncOptions;
use Vitamin2\Sync\Sync;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

/**
 * Shared argument/option resolution for the sync commands.
 *
 * @mixin Command
 */
trait ResolvesSyncInput
{
    use ResolvesRemote;

    /**
     * Resolve the operation, remote, recipes, and rsync options, prompting for whatever is
     * missing. Returns null (after a friendly error) when input references config that
     * doesn't exist or trips a sync guard.
     *
     * Guards run as each of their inputs becomes available, so a violation fails before the
     * next prompt rather than after it — hence building the `PendingSync` directly instead of
     * through `Sync::prepare()`, which would only re-run the same guards.
     */
    protected function resolvePendingSync(): ?PendingSync
    {
        try {
            $sync = $this->syncService();
            $operation = $this->resolveOperation();
            $remote = $this->resolveRemote();

            $sync->guardReadOnly($operation, $remote);

            $recipes = $this->resolveRecipes();

            $sync->guardNotSamePath($remote, $recipes);
            $sync->guardExcludesFromFilesExist($recipes);

            $backup = $this->resolveBackup($operation) ? $sync->startBackup($recipes) : null;

            return new PendingSync($operation, $remote, $recipes, $this->resolveOptions($backup instanceof Backup), $backup);
        } catch (SyncException $exception) {
            $this->error($exception->getMessage());

            return null;
        }
    }

    protected function resolveOperation(): Operation
    {
        $value = $this->resolveArgumentOrPrompt(
            argument: 'operation',
            label: 'Which operation do you want to perform?',
            options: Operation::options(),
            missingException: fn () => SyncException::operationRequired(),
        );

        try {
            return Operation::fromInput($value);
        } catch (ValueError $exception) {
            throw SyncException::invalidOperation($exception);
        }
    }

    /**
     * @return Collection<int, Recipe>
     */
    protected function resolveRecipes(): Collection
    {
        $sync = $this->syncService();

        if ($this->option('all')) {
            return $sync->recipes()->values();
        }

        $names = Sync::filterStrings((array) $this->argument('recipe'));

        if ($names === [] && $this->input->isInteractive()) {
            $names = confirm(label: 'Sync all recipes?', default: false)
                ? $sync->recipes()->keys()->all()
                : multiselect(
                    label: 'Which recipes do you want to sync?',
                    options: $sync->recipes()->keys()->all(),
                    required: true,
                );
        }

        // A purely numeric recipe name (e.g. "2024") arrives as an int: PHP coerces numeric-string
        // array keys wherever names pass through one (config, `recipes()->keys()`, `multiselect()`).
        $names = collect($names)->map(fn (mixed $name) => (string) $name)->values()->all();

        if ($names === []) {
            throw SyncException::noRecipeSelected();
        }

        return collect($names)->map(fn (string $name) => $sync->recipe($name))->values();
    }

    /**
     * Only a real (non-dry) pull overwrites local files, so only that can be backed up.
     * The interactive confirm is additionally gated on `promptsForBackupConfirmation()`, so
     * a preview command never implies an action it doesn't take.
     */
    protected function resolveBackup(Operation $operation): bool
    {
        if ($operation !== Operation::Pull || (bool) $this->option('dry')) {
            return false;
        }

        if ($this->option('backup')) {
            return true;
        }

        return $this->promptsForBackupConfirmation()
            && $this->input->isInteractive()
            && confirm(label: 'Back up the local files before pulling?', default: false);
    }

    /**
     * Defaults to `false`: `sync:list` and `sync:commands` only preview, so confirming a
     * backup would imply an action they never take. `sync` overrides it. `--backup` works
     * either way — this gates only the interactive confirm.
     */
    protected function promptsForBackupConfirmation(): bool
    {
        return false;
    }

    protected function resolveOptions(bool $backup): RsyncOptions
    {
        $configDefaults = $this->syncService()->defaultOptions();
        $verbose = $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
        $cli = collect(Sync::filterStrings((array) $this->option('option')))
            ->filter(fn (string $flag) => $flag !== '')
            ->values()
            ->all();

        $flags = match (true) {
            $cli !== [] => $cli,
            $this->input->isInteractive() => multiselect(
                label: 'Which rsync options do you want to use?',
                options: $this->orderOptionsByDefault($configDefaults, $verbose, $backup),
                default: $this->defaultOptionsForPrompt($configDefaults, $verbose, $backup),
            ),
            default => $configDefaults,
        };

        return RsyncOptions::resolve(
            flags: collect($flags)->map(fn (mixed $flag) => (string) $flag)->values()->all(),
            dry: (bool) $this->option('dry'),
            verbose: $verbose,
            backup: $backup,
        );
    }

    /**
     * Sorts the config-default flags to the front so they're easiest to spot in the prompt.
     *
     * @param  array<int, string>  $configDefaults
     * @return array<string, string>
     */
    private function orderOptionsByDefault(array $configDefaults, bool $verbose, bool $backup): array
    {
        return collect(RsyncOptions::AVAILABLE)
            ->reject(fn (string $label, string $flag) => $this->isExcludedFromOptionsPrompt($flag, $verbose, $backup))
            ->sortBy(fn (string $label, string $flag) => in_array($flag, $configDefaults, true) ? 0 : 1)
            ->all();
    }

    /**
     * The `multiselect()` prompt's default selection. Must exclude exactly what
     * `orderOptionsByDefault()` excludes from the choices — a default referencing an option
     * that isn't offered breaks the prompt when accepted as-is.
     *
     * @param  array<int, string>  $configDefaults
     * @return array<int, string>
     */
    private function defaultOptionsForPrompt(array $configDefaults, bool $verbose, bool $backup): array
    {
        return collect($configDefaults)
            ->reject(fn (string $flag) => $this->isExcludedFromOptionsPrompt($flag, $verbose, $backup))
            ->values()
            ->all();
    }

    /**
     * Hides flags that `RsyncOptions::resolve()` forces on or strips regardless of what's
     * picked, so nobody can "uncheck" a flag that stays on anyway.
     */
    private function isExcludedFromOptionsPrompt(string $flag, bool $verbose, bool $backup): bool
    {
        return ($verbose && in_array($flag, RsyncOptions::OUTPUT_PRODUCING, true))
            || ($backup && $flag === '--backup');
    }
}
