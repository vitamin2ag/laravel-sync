<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Ssh;

use Vitamin2\Sync\Data\Remote;

/**
 * The `ssh` invocation shape shared by every value object in this namespace that runs a
 * single command against a remote over SSH (`ConnectionCommand`, `RsyncAvailableCommand`)
 * — both differ only in which command they run once connected.
 */
final class SshOptions
{
    /**
     * Fail fast instead of hanging: `BatchMode=yes` disables interactive/password auth
     * entirely (agent/key auth only, matching how every other command in this package
     * connects), and `ConnectTimeout=5` bounds how long the initial handshake itself is
     * allowed to take.
     *
     * @var list<string>
     */
    public const array DEFAULT = ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5'];

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
}
