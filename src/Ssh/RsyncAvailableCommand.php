<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Ssh;

use Stringable;
use Vitamin2\Sync\Data\Remote;

/**
 * An `ssh` check for `sync:doctor` — confirms `rsync` is installed and on `PATH` on the
 * remote, without parsing or comparing version output (a mismatched but present `rsync`
 * still runs; this only catches the "not installed at all" failure a real sync would
 * otherwise hit partway through with an opaque error).
 */
final readonly class RsyncAvailableCommand implements Stringable
{
    public function __construct(
        public Remote $remote,
    ) {}

    public function __toString(): string
    {
        return implode(' ', $this->toArgs());
    }

    /**
     * Get this command as an argument list, safe to hand directly to a process runner
     * without shell interpretation.
     *
     * `command -v rsync`, not `which rsync`: `command -v` is a POSIX shell builtin
     * available even when the remote's `PATH` doesn't include a `which` binary at all
     * (some minimal container images ship without one), and its exit code alone (found
     * vs. not found) is all this check needs — nothing here reads its output.
     *
     * @return list<string>
     */
    public function toArgs(): array
    {
        return SshOptions::command($this->remote, 'command -v rsync');
    }
}
