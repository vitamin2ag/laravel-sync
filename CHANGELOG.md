# Release Notes

## [Unreleased](https://github.com/vitamin2ag/laravel-sync/compare/v1.0.0...HEAD)

- Add a concurrency guard: `sync` refuses to run when another `sync` is already in progress for the same remote
- Add `excludes_from` config key: per-recipe rsync `--exclude-from` file support, alongside the existing inline `excludes`
- Add `sync:backups-restore` Artisan command to restore a backup folder's contents back onto the project root (interactive picker, `--dry`, `--force`, `--mirror`)
- Add `sync:doctor` Artisan command to check that rsync and SSH access are ready for a real sync (local `rsync`, SSH connection & root path, remote `rsync`), with `--all` to check every configured remote

## [v1.0.0](https://github.com/vitamin2ag/laravel-sync/compare/v0.3.0...v1.0.0) - 2026-08-25

- Add a concurrency guard: `sync` refuses to run when another `sync` is already in progress for the same remote
- Add `excludes_from` config key: per-recipe rsync `--exclude-from` file support, alongside the existing inline `excludes`
- Add `sync:backups-restore` Artisan command to restore a backup folder's contents back onto the project root (interactive picker, `--dry`, `--force`, `--mirror`)
- Add `sync:doctor` Artisan command to check that rsync and SSH access are ready for a real sync (local `rsync`, SSH connection & root path, remote `rsync`), with `--all` to check every configured remote

## [v0.3.0](https://github.com/vitamin2ag/laravel-sync/compare/v0.2.0...v0.3.0) - 2026-08-19

- Add `sync:backups-clean` Artisan command to delete backup folders (interactive picker, `--all`, `--dry`, `--force`, `--keep=`, `--older-than=`)
- Add `sync:test-connection` Artisan command to test the SSH connection (and root path) for a remote
- Add `excludes` config key: per-recipe `rsync --exclude` patterns, keyed by recipe name
- Move the package to VITAMIN 2: Composer package renamed to `vitamin2/laravel-sync`, PHP namespace renamed to `Vitamin2\Sync\`, repo moved to `github.com/vitamin2ag/laravel-sync`

## [v0.2.0](https://github.com/vitamin2ag/laravel-sync/compare/v0.1.0...v0.2.0) - 2026-08-10

- Add `--backup`/`-B` to back up local files before a real pull, into a timestamped folder under the configurable `backup_dir` (default `.sync-backups`)
- Add Rector for automated refactoring; raise PHPStan to level max

## [v0.1.0](https://github.com/vitamin2ag/laravel-sync/compare/main...v0.1.0) - 2026-07-24

Initial release.

- `sync`, `sync:list`, and `sync:commands` Artisan commands for pushing/pulling files and folders between environments via `rsync`
- Config-driven remotes and recipes (`config/sync.php`: `remotes`, `recipes`, `options`)
- Interactive prompts for anything left unspecified (operation, remote, recipes, rsync options), with a `--no-interaction` fallback for scripts/CI
- Guards against pushing to a `read_only` remote and against syncing a path with itself
- Full Pest test suite, 100% code and type coverage
