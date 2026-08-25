<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Ssh;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Vitamin2\Sync\Data\Remote;

/**
 * The `ssh` invocation shape shared by every value object in this namespace that runs a
 * single command against a remote over SSH (`ConnectionCommand`, `RsyncAvailableCommand`)
 * — both differ only in which command they run once connected — plus the exit-code
 * classification and bounded-timeout execution every caller of those commands needs.
 */
final class SshOptions
{
    /**
     * Fail fast instead of hanging: `BatchMode=yes` disables interactive/password auth
     * entirely (agent/key auth only, matching how every other command in this package
     * connects), and `ConnectTimeout=5` bounds how long the initial handshake itself is
     * allowed to take. `StrictHostKeyChecking=accept-new` auto-trusts a host key seen for
     * the first time (otherwise `BatchMode=yes` turns that prompt into a hard failure) while
     * still rejecting a host key that changed from what's already in `known_hosts`.
     *
     * @var list<string>
     */
    public const array DEFAULT = ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-o', 'StrictHostKeyChecking=accept-new'];

    /**
     * Bounds a whole check (an SSH round trip, or a plain local command run the same
     * way) on top of `DEFAULT`'s own `ConnectTimeout` — a safety net against the remote
     * or local command hanging once started, shared by `sync:test-connection` and
     * `sync:doctor` so they can't drift apart on how long either is willing to wait.
     */
    public const int TIMEOUT_SECONDS = 10;

    /**
     * Build the `ssh` invocation for running `$remoteCommand` on `$remote`, safe to hand
     * directly to a process runner without shell interpretation.
     *
     * @return list<string>
     */
    public static function command(Remote $remote, string $remoteCommand): array
    {
        return [
            'ssh',
            ...self::DEFAULT,
            '-p', (string) $remote->port,
            "{$remote->user}@{$remote->host}",
            $remoteCommand,
        ];
    }

    /**
     * Whether `$result` is `ssh` itself failing (exit 255) rather than the remote command
     * it ran — distinct from a null `$result`, which already means the check timed out and
     * is reported that way regardless of this classification.
     */
    public static function connectionFailed(?ProcessResult $result): bool
    {
        return $result?->exitCode() === 255;
    }

    /**
     * Run `$args` synchronously, bounded by `TIMEOUT_SECONDS`. Returns null on a timeout
     * instead of throwing, so a caller reports it however fits its own output shape.
     *
     * @param  list<string>  $args
     */
    public static function run(array $args): ?ProcessResult
    {
        try {
            return Process::timeout(self::TIMEOUT_SECONDS)->run($args);
        } catch (ProcessTimedOutException) {
            return null;
        }
    }

    /**
     * Start `$args` without blocking, bounded by `TIMEOUT_SECONDS`, so a caller can run
     * several checks concurrently and `wait()` on each afterwards. Returns null if it
     * times out immediately — only reachable under `Process::fake()`, which can resolve
     * a faked timeout eagerly here rather than on `wait()`; a real process never times
     * out before it has even started.
     *
     * @param  list<string>  $args
     */
    public static function start(array $args): ?InvokedProcess
    {
        try {
            return Process::timeout(self::TIMEOUT_SECONDS)->start($args);
        } catch (ProcessTimedOutException) {
            return null;
        }
    }

    /**
     * Wait for a process started by `start()`, bounded by the same `TIMEOUT_SECONDS`.
     * Returns null on a timeout (rather than a failed `ProcessResult`) to keep a hang
     * distinguishable in the reported result.
     */
    public static function wait(?InvokedProcess $process): ?ProcessResult
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
}
