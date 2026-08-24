<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Vitamin2\Sync\Commands\Concerns\ResolvesRemote;
use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\Ssh\ConnectionCommand;
use Vitamin2\Sync\Ssh\RsyncAvailableCommand;
use Vitamin2\Sync\Ssh\SshOptions;
use Vitamin2\Sync\Sync;

use function Laravel\Prompts\table;

/**
 * Uses `ResolvesRemote`, not the full `ResolvesSyncInput` — this command resolves only
 * remotes, none of the operation/recipes/rsync-option shape that trait is built around
 * (see `SyncTestConnectionCommand`'s own docblock for the same reasoning).
 *
 * Reuses `ConnectionCommand` (the same SSH round trip `sync:test-connection` runs) as a
 * value object, not `sync:test-connection` itself: that command's prose is tailored to
 * a single remote, while this one reports a compact table across every check, possibly
 * for every configured remote at once.
 */
class SyncDoctorCommand extends Command
{
    use ResolvesRemote;

    /**
     * Bounds each individual check — both the local `rsync --version` call and each SSH
     * round trip, on top of `ConnectionCommand`'s and `RsyncAvailableCommand`'s own
     * `ConnectTimeout` — a safety net against any one of them hanging once started.
     */
    private const int TIMEOUT_SECONDS = 10;

    protected $signature = 'sync:doctor
        {remote? : The remote to check}
        {--A|all : Check every configured remote}';

    protected $description = 'Check that rsync and SSH access are ready for a real sync';

    public function handle(): int
    {
        try {
            $remotes = $this->resolveRemotes($this->syncService());
        } catch (SyncException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $outcomes = $this->runChecks($remotes);

        table(
            headers: ['Remote', 'Check', 'Result'],
            rows: array_map(fn (array $outcome) => [$outcome[0], $outcome[1], $outcome[2]], $outcomes),
        );

        if (collect($outcomes)->contains(fn (array $outcome) => ! $outcome[3])) {
            $this->error('One or more checks failed — see the table above.');

            return self::FAILURE;
        }

        $this->info('Everything looks good — ready to sync.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Remote>
     */
    private function resolveRemotes(Sync $sync): Collection
    {
        if ($this->option('all')) {
            return $sync->remotes()->values();
        }

        return collect([$this->resolveRemote('Which remote do you want to check?')]);
    }

    /**
     * Every check as a flat, independent outcome (remote, check label, result label,
     * passed) rather than a row/healthy pair threaded through the loop below — adding a
     * check only means appending another outcome here; the table rows and the overall
     * pass/fail in `handle()` are each derived from the full list on their own.
     *
     * Runs each phase across every remote at once (local `rsync` and every remote's SSH
     * connection together, then every still-relevant remote's `rsync` check together),
     * instead of one remote's full round trip at a time — `--all` against several slow
     * or unreachable remotes then costs one connection round trip's worth of wall clock,
     * not the sum of all of them.
     *
     * The remote rsync check is only started for a remote whose connection already
     * succeeded: it would need that very same SSH connection to run, so a failed one can
     * only fail again — starting it anyway would needlessly double the failed attempts.
     *
     * @param  Collection<int, Remote>  $remotes
     * @return list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    private function runChecks(Collection $remotes): array
    {
        $local = $this->start(['rsync', '--version']);

        $connections = $remotes->reject(fn (Remote $remote) => $remote->isLocal())
            ->mapWithKeys(fn (Remote $remote) => [$remote->name => $this->start((new ConnectionCommand($remote))->toArgs())]);

        $localResult = $this->wait($local);
        $outcomes = [[
            '-',
            'Local rsync',
            $this->resultLabel($localResult?->successful(), 'not found on PATH'),
            $localResult?->successful() === true,
        ]];

        $connectionResults = $connections->map(fn (?InvokedProcess $process) => $this->wait($process));

        $rsyncChecks = $remotes
            ->reject(fn (Remote $remote) => $remote->isLocal() || $connectionResults[$remote->name]?->successful() !== true)
            ->mapWithKeys(fn (Remote $remote) => [$remote->name => $this->start((new RsyncAvailableCommand($remote))->toArgs())]);

        $rsyncResults = $rsyncChecks->map(fn (?InvokedProcess $process) => $this->wait($process));

        foreach ($remotes as $remote) {
            if ($remote->isLocal()) {
                $outcomes[] = [$remote->name, 'SSH connection', 'skipped, local remote', true];

                continue;
            }

            $connected = $connectionResults[$remote->name]?->successful();
            $outcomes[] = [$remote->name, 'SSH connection', $this->resultLabel($connected, 'see sync:test-connection'), $connected === true];

            if ($connected !== true) {
                $outcomes[] = [$remote->name, 'Remote rsync', 'skipped, SSH connection failed', false];

                continue;
            }

            $rsync = $rsyncResults[$remote->name];
            $rsyncAvailable = $rsync?->successful();
            $rsyncFailureReason = SshOptions::connectionFailed($rsync) ? 'SSH connection failed unexpectedly' : 'not found on remote';
            $outcomes[] = [$remote->name, 'Remote rsync', $this->resultLabel($rsyncAvailable, $rsyncFailureReason), $rsyncAvailable === true];
        }

        return $outcomes;
    }

    /**
     * Start a check (local or over SSH) without blocking, bounded by `TIMEOUT_SECONDS`.
     * Returns null if it times out immediately (only reachable under `Process::fake()`,
     * which resolves a faked timeout eagerly here rather than on `wait()`); a real
     * process never times out before it has even started.
     *
     * @param  list<string>  $args
     */
    private function start(array $args): ?InvokedProcess
    {
        try {
            return Process::timeout(self::TIMEOUT_SECONDS)->start($args);
        } catch (ProcessTimedOutException) {
            return null;
        }
    }

    /**
     * Wait for a check started by `start()`, bounded by the same `TIMEOUT_SECONDS`.
     * Returns null on a timeout (rather than a failed `ProcessResult`) to keep a hang
     * distinguishable in the reported result; returns the full `ProcessResult`
     * otherwise (not just its `successful()` bool) so a caller can inspect the exit
     * code for a more specific failure reason, e.g. distinguishing an `ssh`-level
     * failure from the remote command's own.
     */
    private function wait(?InvokedProcess $process): ?ProcessResult
    {
        if (! $process instanceof InvokedProcess) {
            return null;
        }

        try {
            return $process->wait();
        } catch (ProcessTimedOutException) {
            return null;
        }
    }

    private function resultLabel(?bool $result, string $failureReason): string
    {
        return match ($result) {
            true => 'OK',
            false => "FAILED ({$failureReason})",
            null => sprintf('FAILED (timed out after %d seconds)', self::TIMEOUT_SECONDS),
        };
    }
}
