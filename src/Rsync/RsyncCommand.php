<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Rsync;

use Illuminate\Contracts\Support\Arrayable;
use Stringable;
use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Enums\Operation;

/**
 * @implements Arrayable<string, string>
 */
final readonly class RsyncCommand implements Arrayable, Stringable
{
    public function __construct(
        public Operation $operation,
        public Remote $remote,
        public string $path,
        public RsyncOptions $options,
    ) {}

    public function origin(): string
    {
        return $this->operation === Operation::Pull
            ? $this->remote->path($this->path)
            : $this->localPath();
    }

    public function target(): string
    {
        return $this->operation === Operation::Pull
            ? $this->localPath()
            : $this->remote->path($this->path);
    }

    public function __toString(): string
    {
        $ssh = $this->remote->isLocal() ? '' : "-e '{$this->sshFlag()}' ";

        return "rsync {$ssh}{$this->options} {$this->origin()} {$this->target()}";
    }

    /**
     * The command as an argument list, so a process runner applies no shell interpretation
     * to paths or options.
     *
     * @return list<string>
     */
    public function toArgs(): array
    {
        $ssh = $this->remote->isLocal() ? [] : ['-e', $this->sshFlag()];

        return [
            'rsync',
            ...$ssh,
            ...$this->options->flags,
            $this->origin(),
            $this->target(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'origin' => $this->origin(),
            'target' => $this->target(),
            'options' => (string) $this->options,
            'port' => $this->remote->isLocal() ? '-' : (string) $this->remote->port,
        ];
    }

    private function localPath(): string
    {
        return base_path($this->path);
    }

    /**
     * The `ssh` command rsync's `-e` flag runs, carrying the remote's port.
     * `StrictHostKeyChecking=accept-new` auto-trusts a host key seen for the first time
     * instead of rsync failing outright on the interactive known_hosts prompt it can't
     * answer, while still rejecting a host key that changed from what's already known.
     */
    private function sshFlag(): string
    {
        return "ssh -p {$this->remote->port} -o StrictHostKeyChecking=accept-new";
    }
}
