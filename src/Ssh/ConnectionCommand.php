<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Ssh;

use Stringable;
use Vitamin2\Sync\Data\Remote;

/**
 * An `ssh` connectivity check for `sync:test-connection` — authenticates and confirms
 * the remote's `root` exists, in one round trip.
 */
final readonly class ConnectionCommand implements Stringable
{
    public function __construct(
        public Remote $remote,
    ) {}

    public function __toString(): string
    {
        return implode(' ', $this->toArgs());
    }

    /**
     * The command as an argument list, so a process runner applies no shell interpretation
     * to paths or options.
     *
     * The trailing `test -d` runs on the *remote* shell, so one round trip catches a
     * misconfigured `root` as well as a broken connection.
     *
     * @return list<string>
     */
    public function toArgs(): array
    {
        return SshOptions::command($this->remote, "test -d {$this->escapeRemotePath($this->root())}");
    }

    /**
     * `Remote::fromArray()` rtrims trailing slashes, turning a configured root of `/` into
     * `''`; restore it so a filesystem-root remote stays a checkable path.
     */
    private function root(): string
    {
        return $this->remote->root === '' ? '/' : $this->remote->root;
    }

    /**
     * Single-quote a path for the *remote* POSIX shell, not `escapeshellarg()`: that escapes
     * for the control machine's shell, whose Windows quoting rules aren't even POSIX.
     */
    private function escapeRemotePath(string $path): string
    {
        return "'".str_replace("'", "'\\''", $path)."'";
    }
}
