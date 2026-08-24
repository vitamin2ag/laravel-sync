<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Rsync;

use Illuminate\Contracts\Support\Arrayable;
use Stringable;
use Vitamin2\Sync\Data\Backup;

/**
 * A local copy of one recipe path into a timestamped backup folder, run before a real
 * pull so the local files being overwritten aren't lost.
 *
 * @implements Arrayable<string, string>
 */
final readonly class BackupCommand implements Arrayable, Stringable
{
    /**
     * Fixed, not user-overridable: `--archive` for a faithful copy, `--relative` to recreate
     * the path's directory structure under the backup folder.
     */
    private const array OPTIONS = ['--archive', '--relative'];

    public function __construct(
        public string $path,
        public Backup $backup,
    ) {}

    /**
     * The source path, kept relative so `--relative` recreates only the recipe path under
     * the backup folder. Requires the process to run from the project root (`PendingSync::run()`).
     */
    public function origin(): string
    {
        // Not the `/./` anchor on an absolute path: macOS's bundled openrsync ignores it and
        // replicates the full absolute path.
        return $this->path;
    }

    /**
     * The timestamped destination folder for this backup run.
     */
    public function target(): string
    {
        $dir = rtrim($this->backup->dir, '/');

        return base_path("{$dir}/{$this->backup->timestamp}").'/';
    }

    public function __toString(): string
    {
        $options = implode(' ', self::OPTIONS);

        return "(cd {$this->workingDirectory()} && rsync {$options} {$this->origin()} {$this->target()})";
    }

    /**
     * The working directory this command must run from, so its relative `origin()` resolves.
     */
    public function workingDirectory(): string
    {
        return base_path();
    }

    /**
     * The command as an argument list, so a process runner applies no shell interpretation
     * to paths or options.
     *
     * @return list<string>
     */
    public function toArgs(): array
    {
        return ['rsync', ...self::OPTIONS, $this->origin(), $this->target()];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'origin' => base_path($this->path),
            'target' => $this->target().$this->path,
            'options' => implode(' ', self::OPTIONS),
            'port' => '-',
        ];
    }
}
