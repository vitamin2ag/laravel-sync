<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Data;

final readonly class Recipe
{
    /**
     * Trimmed and `/`-normalized, so the guard that validates these files and the
     * `--exclude-from=` flag built from them address the same file. Normalized here rather
     * than in `fromArray()` because `Sync::prepare()` accepts any caller-built `Recipe`,
     * and POSIX reads an unnormalized backslash path as one oddly-named segment.
     *
     * @var array<int, string>
     */
    public array $excludesFrom;

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $excludes
     * @param  array<int, string>  $excludesFrom
     */
    public function __construct(
        public string $name,
        public array $paths,
        public array $excludes = [],
        array $excludesFrom = [],
    ) {
        $this->excludesFrom = array_map(self::normalizePathSeparators(...), $excludesFrom);
    }

    /**
     * Trim and `/`-normalize a path, shared with `Sync::guardBackupDirSafe()` so both sites
     * that must agree on a path's segments normalize identically.
     */
    public static function normalizePathSeparators(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    /**
     * Excludes and excludes-from files arrive separately, from the `sync.excludes`/
     * `sync.excludes_from` keys, so `recipes` keeps the flat shape `aerni/sync` requires.
     *
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $excludes
     * @param  array<int, string>  $excludesFrom
     */
    public static function fromArray(string $name, array $paths, array $excludes = [], array $excludesFrom = []): self
    {
        return new self(name: $name, paths: $paths, excludes: $excludes, excludesFrom: $excludesFrom);
    }
}
