---
name: laravel-sync-development
description: >
  Configure and use the Laravel Sync package to sync files and folders between environments via rsync.
license: MIT
metadata:
  author: VITAMIN 2
---

# Laravel Sync

Use this skill when a Laravel application needs to install or use the `vitamin2/laravel-sync` package to
push or pull files and folders between environments (e.g. local, staging, production) over `rsync`+`ssh`.

## Primary Goal

- apply the `vitamin2/laravel-sync` package's public API (config, `sync`, `sync:list`, `sync:commands`, `sync:backups-clean`, `sync:test-connection`) in the smallest correct way

## Prerequisites

- `rsync` installed on both the local machine and the remote host
- a working `ssh` setup (agent or `~/.ssh/config`) between the local machine and the remote host — the package
  does not manage SSH keys or credentials itself

## Workflow

### 1. Install and publish the config

```bash
composer require vitamin2/laravel-sync
php artisan vendor:publish --tag="laravel-sync-config"
```

This publishes `config/sync.php` with six keys: `remotes`, `recipes`, `options`, `excludes`, `excludes_from`,
`backup_dir`.

### 2. Define remotes

Each remote is keyed by name under `sync.remotes` and needs a `root` (absolute path on that remote):

```php
'remotes' => [
    'production' => [
        'user' => 'forge',
        'host' => '104.26.3.113',
        'port' => 22, // optional, defaults to 22
        'root' => '/home/forge/example.com',
        'read_only' => env('SYNC_PRODUCTION_READ_ONLY', true), // optional, defaults to false
    ],
],
```

Omit both `user` and `host` to treat a remote as a local path — no `ssh` is used, and the `rsync` command runs
without the `-e ssh` flag or a `user@host:` prefix. Useful for syncing between two projects on the same machine.

A `read_only` remote rejects `push` (throws a clear error before anything runs) but still allows `pull`.

### 3. Define recipes

Each recipe under `sync.recipes` is a named list of paths, relative to the Laravel app's root, that get synced
together:

```php
'recipes' => [
    'assets' => ['storage/app/assets/', 'storage/app/img/'],
    'env' => ['.env'],
],
```

### 4. Set default rsync options (optional)

```php
'options' => [
    '--archive',
],
```

Used whenever a command doesn't receive explicit `-O`/`--option` flags.

### 5. Set per-recipe excludes (optional)

```php
'excludes' => [
    'assets' => ['*.log', 'node_modules/'],
],
```

Keyed by recipe name. Appended as `rsync --exclude` flags only when that recipe is synced, on top of `options`
above — not applied to a `--backup` pass, which is a fixed, independent full copy. A path shared by more than
one synced recipe gets the union of every one of those recipes' excludes.

### 6. Set per-recipe excludes-from files (optional)

```php
'excludes_from' => [
    'assets' => ['.rsync-excludes'],
],
```

Keyed by recipe name. Each entry is a file path containing rsync exclude patterns (one per line) — applied via
`rsync --exclude-from` alongside (not instead of) `excludes` above. A relative path is resolved from the app's
root; an absolute one (e.g. `storage_path('app/.rsync-excludes')`) is used as written. The file need not sit
inside the project. A configured file that doesn't exist fails fast with a clear error before anything is
synced.

### 7. Set the backup directory (optional)

```php
'backup_dir' => '.sync-backups',
```

Relative to the app's root. Used when `--backup` is passed on a real pull (see step 8).

### 8. Run a sync

```bash
php artisan sync {push|pull} {remote} {recipe...} [options]
```

Any of `operation`, `remote`, or `recipe` that is omitted is prompted for interactively — unless
`--no-interaction` is passed, in which case a missing required value fails fast with a clear error instead of
prompting. A real (non-dry) sync asks for confirmation before running unless `--dry` or `--no-interaction` is
set.

Options: `-O`/`--option=*` (override default rsync options, repeatable), `-D`/`--dry` (dry run with real-time
output), `-A`/`--all` (sync every recipe), `-B`/`--backup` (back up local files before a real pull), `-v`
(stream real-time output).

`--backup` only applies to a real (non-dry) `pull` — a push or a dry run silently ignores it, since only a
pull overwrites local files. Before the pull runs, the local files of the selected recipes are copied into
`base_path("{backup_dir}/{timestamp}/...")` via a fixed `rsync --archive --relative` pass (independent of the
sync's own rsync options); if that copy fails, the pull doesn't run. Pulling interactively without `--backup`
prompts "Back up the local files before pulling?" before the rsync-options prompt.

Two `sync` runs against the same remote cannot overlap. The second fails immediately with `Could not start a
sync for "{remote}": another sync may already be running for it` rather than racing the first. This matters
most when a scheduled task and a person can both trigger a sync — treat that error as "retry later", not as a
misconfiguration. There is nothing to configure, and the lock is released when the run ends, including on
failure or a declined confirmation prompt.

The lock is keyed by the remote's resolved target (`host:port` plus `root`, or just `root` when local), not by
its config name, so two config entries aliasing the same physical directory still block each other.

### 9. Preview before running (optional)

- `php artisan sync:list {push|pull} {remote} {recipe...}` — table of origin, target, options, and port
- `php artisan sync:commands {push|pull} {remote} {recipe...}` — prints the exact `rsync` command(s) that would run

Neither of these two commands syncs anything; they only resolve and display.

### 10. Clean up old backups (optional)

```bash
php artisan sync:backups-clean
```

Deletes timestamped folders under `backup_dir`, leaving `backup_dir` itself (and anything in it that isn't a
timestamped backup folder) untouched. With no options, it prompts with a multiselect listing each backup's
name, size, and age. Options: `-A`/`--all` (select every backup instead of prompting), `-D`/`--dry` (preview
what would be deleted, deletes nothing), `-F`/`--force` (skip the confirmation prompt), `-K`/`--keep=` (keep
the N newest, delete the rest), `--older-than=` (delete backups older than N days).

Running it with `--no-interaction` and without `--all` (or `--keep`/`--older-than`) fails fast with a clear
error instead of deleting anything — there's no picker to fall back to. The confirmation prompt only shows
when running interactively, so `--no-interaction --all` (or `--keep`/`--older-than`, e.g. in a scheduled task)
deletes immediately without needing `--force`. `--keep`/`--older-than` cannot be combined with `--all`.

### 11. Test a remote's connection (optional)

```bash
php artisan sync:test-connection {remote}
```

Authenticates to the remote over SSH and confirms its `root` exists, without syncing anything — catches a
misconfigured remote or broken SSH setup before a real sync fails partway through. A local remote (no
`user`/`host`) reports success immediately, without opening any connection.

## Rules, References, and Templates

Read before executing:

- `config/sync.php` — the published config file with all available keys
- `README.md` — full usage examples and the options/commands table

## Examples

```bash
# Pull the "assets" recipe from "staging" to the local project
php artisan sync pull staging assets

# Push "assets" to "production" with custom rsync options
php artisan sync push production assets --option=-avh --option=--delete

# Dry-run a pull with real-time output
php artisan sync pull staging assets --dry

# Pull "assets" from "staging", backing up the local files first
php artisan sync pull staging assets --backup

# Sync every recipe non-interactively (e.g. in a deploy script)
php artisan sync push production --all --no-interaction

# Preview the exact rsync command without running it
php artisan sync:commands push production assets

# Delete every backup without a confirmation prompt (e.g. in a scheduled task)
php artisan sync:backups-clean --all --force

# Cron-safe cleanup: keep the 5 newest backups, delete anything else older than 30 days
php artisan sync:backups-clean --keep=5 --older-than=30 --no-interaction

# Check the SSH connection and root path for a remote before syncing
php artisan sync:test-connection production
```

## Anti-patterns

- do not document package internals (DTOs, the `Sync` service, `RsyncCommand`/`RsyncOptions`/`BackupCommand`/`BackupFolder` value objects) here; keep the skill focused on adoption in Laravel apps
- do not suggest managing SSH keys, passwords, or host verification through this package — it relies entirely on the host machine's existing `ssh` setup
- do not add a `push`/`pull` for a `read_only` remote as a documented workaround; it is a deliberate guard
- do not retry or loop around the "another sync may already be running" error to force a sync through; it means a concurrent run holds the remote, and the correct response is to wait
