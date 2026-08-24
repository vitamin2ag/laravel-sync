<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Collection;
use Vitamin2\Sync\Commands\Concerns\ResolvesRemote;
use Vitamin2\Sync\Data\CheckOutcome;
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
            rows: array_map(fn (CheckOutcome $outcome) => $outcome->toRow(), $outcomes),
        );

        if (collect($outcomes)->contains(fn (CheckOutcome $outcome) => ! $outcome->passed)) {
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
     * Runs each phase across every remote at once (local `rsync` and every remote's SSH
     * connection together, then every still-connected remote's `rsync` check together)
     * instead of one remote's full round trip at a time — `--all` against several slow
     * or unreachable remotes then costs one connection round trip's worth of wall clock,
     * not the sum of all of them.
     *
     * The remote rsync check is only started for a remote whose connection already
     * succeeded: it would need that very same SSH connection to run, so a failed one can
     * only fail again — starting it anyway would needlessly double the failed attempts.
     *
     * @param  Collection<int, Remote>  $remotes
     * @return list<CheckOutcome>
     */
    private function runChecks(Collection $remotes): array
    {
        $remoteRemotes = $remotes->reject(fn (Remote $remote) => $remote->isLocal());

        $local = SshOptions::start(['rsync', '--version']);

        $connections = $remoteRemotes->mapWithKeys(
            fn (Remote $remote) => [$remote->name => SshOptions::start((new ConnectionCommand($remote))->toArgs())],
        );

        $localResult = SshOptions::wait($local);
        $localAvailable = $localResult?->successful();
        $outcomes = [new CheckOutcome(
            '-',
            'Local rsync',
            $this->resultLabel($localAvailable, $this->localFailureReason($localResult)),
            $localAvailable === true,
        )];

        $connectionResults = $connections->map(fn (?InvokedProcess $process) => SshOptions::wait($process));
        $connected = fn (Remote $remote): bool => $connectionResults[$remote->name]?->successful() === true;

        $rsyncChecks = $remoteRemotes->filter($connected)->mapWithKeys(
            fn (Remote $remote) => [$remote->name => SshOptions::start((new RsyncAvailableCommand($remote))->toArgs())],
        );

        $rsyncResults = $rsyncChecks->map(fn (?InvokedProcess $process) => SshOptions::wait($process));

        foreach ($remotes as $remote) {
            if ($remote->isLocal()) {
                $outcomes[] = new CheckOutcome($remote->name, 'SSH connection', 'skipped, local remote', true);

                continue;
            }

            $connectionResult = $connectionResults[$remote->name];
            $isConnected = $connected($remote);
            $outcomes[] = new CheckOutcome(
                $remote->name,
                'SSH connection',
                $this->resultLabel($connectionResult?->successful(), $this->connectionFailureReason($connectionResult)),
                $isConnected,
            );

            if (! $isConnected) {
                $skipReason = $connectionResult === null ? 'skipped, SSH connection timed out' : 'skipped, SSH connection failed';
                $outcomes[] = new CheckOutcome($remote->name, 'Remote rsync', $skipReason, false);

                continue;
            }

            $rsync = $rsyncResults[$remote->name];
            $rsyncAvailable = $rsync?->successful();
            $outcomes[] = new CheckOutcome(
                $remote->name,
                'Remote rsync',
                $this->resultLabel($rsyncAvailable, $this->rsyncFailureReason($rsync)),
                $rsyncAvailable === true,
            );
        }

        return $outcomes;
    }

    /**
     * `rsync` missing from `PATH` entirely exits 127 — the shell's own, well-established
     * convention for "command not found" — distinct from `rsync` being present but
     * broken (bad permissions, wrong architecture, ...), which exits some other nonzero
     * code and shouldn't be misdiagnosed as missing.
     */
    private function localFailureReason(?ProcessResult $result): string
    {
        return $result?->exitCode() === 127 ? 'not found on PATH' : 'rsync check failed';
    }

    /**
     * Distinguishes `ssh` itself failing (exit 255) from `test -d`'s own nonzero exit —
     * the latter still just means "see sync:test-connection", since that command is the
     * one built to tell root-missing and connection-broken apart in detail.
     */
    private function connectionFailureReason(?ProcessResult $result): string
    {
        return SshOptions::connectionFailed($result) ? 'SSH connection failed' : 'see sync:test-connection';
    }

    /**
     * `ssh` itself (not the remote command it ran) exits 255 specifically when the
     * connection drops or fails mid-session — distinct from `command -v rsync`'s own
     * exit code, which the just-succeeded connection check already proved reachable.
     * Reporting that as "not found on remote" would misdiagnose a second, unrelated
     * connection failure as a missing binary.
     */
    private function rsyncFailureReason(?ProcessResult $result): string
    {
        return SshOptions::connectionFailed($result) ? 'SSH connection failed unexpectedly' : 'not found on remote';
    }

    private function resultLabel(?bool $result, string $failureReason): string
    {
        return match ($result) {
            true => 'OK',
            false => "FAILED ({$failureReason})",
            null => sprintf('FAILED (timed out after %d seconds)', SshOptions::TIMEOUT_SECONDS),
        };
    }
}
