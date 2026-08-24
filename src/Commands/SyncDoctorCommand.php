<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Vitamin2\Sync\Commands\Concerns\ResolvesRemote;
use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\Ssh\ConnectionCommand;
use Vitamin2\Sync\Ssh\RsyncAvailableCommand;
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

        $localRsync = $this->runWithTimeout(['rsync', '--version']);
        $localRsyncAvailable = $localRsync?->successful();
        $rows = [['-', 'Local rsync', $this->resultLabel($localRsyncAvailable, 'not found on PATH')]];
        $healthy = $localRsyncAvailable === true;

        foreach ($remotes as $remote) {
            ['rows' => $remoteRows, 'healthy' => $remoteHealthy] = $this->checkRemote($remote);
            $rows = [...$rows, ...$remoteRows];
            $healthy = $healthy && $remoteHealthy;
        }

        table(headers: ['Remote', 'Check', 'Result'], rows: $rows);

        if (! $healthy) {
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
     * Run every check for a single remote, reporting both its table rows and whether it
     * passed — as a typed pair, not inferred later from the rendered row text, which
     * would silently break if `resultLabel()`'s wording ever changed.
     *
     * A local remote (no `user`/`host`) skips the SSH checks entirely — there's no
     * connection to test, and the local `rsync` check already covers it.
     *
     * @return array{rows: list<array{0: string, 1: string, 2: string}>, healthy: bool}
     */
    private function checkRemote(Remote $remote): array
    {
        if ($remote->isLocal()) {
            return ['rows' => [[$remote->name, 'SSH connection', 'skipped, local remote']], 'healthy' => true];
        }

        $connection = $this->runWithTimeout((new ConnectionCommand($remote))->toArgs());
        $connected = $connection?->successful();
        $rows = [[$remote->name, 'SSH connection', $this->resultLabel($connected, 'see sync:test-connection')]];

        // Skip the remote rsync check entirely when the connection itself already
        // failed: it would need the very same SSH connection to run, so it can only
        // fail too — reporting "not found on remote" in that case would misdiagnose
        // an unreachable host or bad auth as a missing rsync binary, and running it
        // anyway would needlessly double the failed SSH connection attempts.
        if ($connected !== true) {
            $rows[] = [$remote->name, 'Remote rsync', 'skipped, SSH connection failed'];

            return ['rows' => $rows, 'healthy' => false];
        }

        $rsync = $this->runWithTimeout((new RsyncAvailableCommand($remote))->toArgs());
        $rsyncAvailable = $rsync?->successful();

        // `ssh` itself (not the remote command it ran) exits 255 specifically when the
        // connection drops or fails mid-session — distinct from `command -v rsync`'s own
        // exit code, which the just-succeeded connection check already proved reachable.
        // Reporting that as "not found on remote" would misdiagnose a second, unrelated
        // connection failure as a missing binary.
        $rsyncFailureReason = $rsync?->exitCode() === 255 ? 'SSH connection failed unexpectedly' : 'not found on remote';
        $rows[] = [$remote->name, 'Remote rsync', $this->resultLabel($rsyncAvailable, $rsyncFailureReason)];

        return ['rows' => $rows, 'healthy' => $rsyncAvailable === true];
    }

    /**
     * Run a check (local or over SSH), bounded by `TIMEOUT_SECONDS`. Returns null on a
     * timeout (rather than a failed `ProcessResult`) to keep a hang distinguishable in
     * the reported result; returns the full `ProcessResult` otherwise (not just its
     * `successful()` bool) so a caller can inspect the exit code for a more specific
     * failure reason, e.g. distinguishing an `ssh`-level failure from the remote
     * command's own.
     *
     * @param  list<string>  $args
     */
    private function runWithTimeout(array $args): ?ProcessResult
    {
        try {
            return Process::timeout(self::TIMEOUT_SECONDS)->run($args);
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
