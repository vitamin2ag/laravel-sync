<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Data;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * One timestamped folder on disk under `backup_dir`, created by a backed-up pull.
 */
final readonly class BackupFolder
{
    public function __construct(
        public string $name,
        public string $path,
        public int $size,
        public Carbon $createdAt,
        public ?string $canonicalPath,
    ) {}

    /**
     * Hydrate a backup folder from its absolute path and size on disk.
     *
     * `createdAt` comes from the folder name, which is its own `Backup::FORMAT` timestamp,
     * not from the filesystem's platform-dependent creation/modification time.
     */
    public static function fromPath(string $path, int $size): self
    {
        return self::tryFromPath($path, fn () => $size) ?? throw new InvalidArgumentException(sprintf(
            '"%s" is not a valid backup timestamp (expected the "%s" format).',
            basename($path),
            Backup::FORMAT,
        ));
    }

    /**
     * Hydrate a backup folder from its absolute path, or return null if the name isn't a
     * valid backup timestamp.
     *
     * `$size` is a callback, not a plain value, so an invalid folder name is rejected
     * before paying for its recursive size calculation.
     *
     * `canonicalPath` pins the folder's real, symlink-resolved location at this moment —
     * the identity `Sync::isUnsafeToActOn()` re-checks against before acting, so a later
     * repoint can't be mistaken for the folder that was actually listed.
     *
     * @param  callable(): int  $size
     */
    public static function tryFromPath(string $path, callable $size): ?self
    {
        $name = basename($path);
        $parsed = self::parse($name);

        if ($parsed === false) {
            return null;
        }

        $realPath = realpath($path);

        return new self(
            name: $name,
            path: $path,
            size: $size(),
            createdAt: Date::instance($parsed),
            canonicalPath: $realPath === false ? null : str_replace('\\', '/', $realPath),
        );
    }

    /**
     * Parse a folder name against `Backup::FORMAT`, rejecting it if the format doesn't match.
     *
     * Native `DateTimeImmutable`, not `Carbon::createFromFormat()`: the native parser returns
     * `false` on a mismatch instead of throwing. The round-trip check rejects an in-shape but
     * out-of-range name (e.g. "2026-13-45_999999") that it would otherwise silently roll over.
     */
    private static function parse(string $name): DateTimeImmutable|false
    {
        $parsed = DateTimeImmutable::createFromFormat(Backup::FORMAT, $name);

        if ($parsed === false || $parsed->format(Backup::FORMAT) !== $name) {
            return false;
        }

        return $parsed;
    }

    /**
     * A human-readable label for interactive prompts and previews, e.g.
     * "2026-07-24_134530 (12.4 MB, 2 weeks ago)".
     */
    public function label(): string
    {
        return sprintf('%s (%s, %s)', $this->name, $this->formattedSize(), $this->age());
    }

    /**
     * The folder's size, formatted for display (e.g. "12.4 MB").
     */
    public function formattedSize(): string
    {
        return Number::fileSize($this->size, precision: 1);
    }

    /**
     * How long ago the folder was created (e.g. "2 weeks ago").
     */
    public function age(): string
    {
        return $this->createdAt->diffForHumans();
    }
}
