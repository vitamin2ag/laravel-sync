<div align="center">
    <h1>Laravel Sync</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/vitamin2/laravel-sync"><img src="https://img.shields.io/packagist/v/vitamin2/laravel-sync.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/vitamin2/laravel-sync"><img src="https://img.shields.io/packagist/dependency-v/vitamin2/laravel-sync/php.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/vitamin2/laravel-sync"><img src="https://badge.laravel.cloud/badge/vitamin2/laravel-sync?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/vitamin2ag/laravel-sync/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/vitamin2ag/laravel-sync/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/vitamin2/laravel-sync"><img src="https://img.shields.io/packagist/dt/vitamin2/laravel-sync.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A git-like artisan command to easily sync files and folders between environments via `rsync`.

## Contents

- [Quick Start](#quick-start)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Remotes](#remotes)
  - [Recipes](#recipes)
  - [Options](#options)
  - [Excludes](#excludes)
  - [Excludes From](#excludes-from)
  - [Backup Directory](#backup-directory)
- [Usage](#usage)
  - [Cleaning Up Backups](#cleaning-up-backups)
  - [Testing a Connection](#testing-a-connection)
  - [Concurrency](#concurrency)
- [Examples](#examples)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Security Vulnerabilities](#security-vulnerabilities)
- [Credits](#credits)
- [License](#license)

## Quick Start

```bash
composer require vitamin2/laravel-sync
php artisan vendor:publish --tag="laravel-sync-config"
```

Add a remote and a recipe to the published `config/sync.php`:

```php
'remotes' => [
    'production' => [
        'user' => 'forge',
        'host' => '104.26.3.113',
        'root' => '/home/forge/example.com',
    ],
],

'recipes' => [
    'assets' => ['storage/app/assets/', 'storage/app/img/'],
],
```

Then check the connection and try it out:

```bash
# Confirm SSH access and that "root" exists on the remote
php artisan sync:test-connection production

# Dry run: connects and reports what would change, without writing anything
php artisan sync pull production assets --dry

# Pull it for real
php artisan sync pull production assets
```

Every config key is explained below in [Configuration](#configuration), and every command and option in
[Usage](#usage) — or jump straight to [Examples](#examples) for more copy-pasteable commands.

## Requirements

- `rsync` on both your local machine and the remote host
- A working `ssh` setup between your local machine and the remote host (agent or `~/.ssh/config`)

## Installation

You can install the package via Composer:

```bash
composer require vitamin2/laravel-sync
```

Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-sync-config"
```

This publishes `config/sync.php`:

```php
return [

    'remotes' => [

        // 'production' => [
        //     'user' => 'forge',
        //     'host' => '104.26.3.113',
        //     'port' => 22,
        //     'root' => '/home/forge/example.com',
        //     'read_only' => env('SYNC_PRODUCTION_READ_ONLY', true),
        // ],

    ],

    'recipes' => [

        // 'assets' => ['storage/app/assets/', 'storage/app/img/'],

    ],

    'options' => [
        '--archive',
    ],

];
```

## Configuration

### Remotes

Each remote needs a `root` path. Add `user` and `host` to sync with an actual server over `ssh`; omit both to
treat the remote as a plain local path (handy for syncing between two projects on the same machine, no `ssh`
involved).

| Key | Description |
| --- | --- |
| `user` | The username to log in to the host. Omit together with `host` for a local remote. |
| `host` | The IP address or hostname of the server. Omit together with `user` for a local remote. |
| `port` | The SSH port to use. Defaults to `22`. |
| `root` | The absolute path to the project's root folder. |
| `read_only` | When `true`, blocks `push` to this remote. Defaults to `false`. |

Once an SSH remote (`user`/`host`) is configured, run `php artisan sync:test-connection <remote>` to confirm
access and that `root` exists before you rely on it for a real sync — see
[Testing a Connection](#testing-a-connection). A local remote reports success immediately without checking
`root`, since there's no connection to test.

### Recipes

Recipes name a set of paths, relative to your project's root, that belong together:

```php
'recipes' => [
    'assets' => ['storage/app/assets/', 'storage/app/img/'],
    'env' => ['.env'],
],
```

### Options

The default `rsync` options, used whenever `--option` isn't passed on the command line:

```php
'options' => [
    '--archive',
],
```

### Excludes

Optional, keyed by recipe name. An array of rsync `--exclude` patterns applied only when
that recipe is synced, on top of the options above:

```php
'excludes' => [
    'assets' => ['*.log', 'node_modules/'],
],
```

If a path appears in more than one recipe you sync together, its command gets the union of
every one of those recipes' excludes for that path. Excludes only apply to the sync itself —
a `--backup` pass still copies the full path, since it's a fixed, independent copy (see
[Backup Directory](#backup-directory)), not shaped by any rsync option.

### Excludes From

Optional, keyed by recipe name. An array of file paths (relative to your project's root), each
containing rsync exclude patterns (one per line), applied via rsync's own `--exclude-from` when
that recipe is synced — useful for a long exclude list you'd rather keep in its own file than
inline in `excludes`:

```php
'excludes_from' => [
    'assets' => ['.rsync-excludes'],
],
```

Combines with `excludes` rather than replacing it. A configured file that doesn't exist fails
fast with a friendly error before anything is synced — checked only for the recipe(s) actually
being synced, not every recipe defined in your config.

A relative path is resolved from your project's root; an absolute one is used as written, so
`storage_path('app/.rsync-excludes')` works as you'd expect. The file need not sit inside the
project either — a `..` segment or a symlink pointing out both resolve as written, so a sibling
checkout or a shared `storage` can hold the list.

### Backup Directory

Relative to your project's root. When `--backup` is passed on a real pull, the local files
of the selected recipes are copied here, into a timestamped folder, before the pull runs:

```php
'backup_dir' => '.sync-backups',
```

Each backed-up pull adds another timestamped folder; nothing prunes old ones automatically. Add
`backup_dir` to your `.gitignore` and run `php artisan sync:backups-clean` to clean it out periodically —
see [Cleaning Up Backups](#cleaning-up-backups).

## Usage

```bash
php artisan sync {push|pull} {remote} {recipe...} [options]
```

| Command | Description |
| --- | --- |
| `sync` | Run the sync. |
| `sync:list` | Preview the origin, target, options, and port in a table, without syncing. |
| `sync:commands` | Print the `rsync` commands that would be run, without syncing. |
| `sync:backups-clean` | Delete backup folders created by a backed-up pull. |
| `sync:test-connection` | Test the SSH connection (and root path) for a remote. |

| Option | Description |
| --- | --- |
| `-O`, `--option=*` | Override the default rsync options. Repeatable. |
| `-D`, `--dry` | Perform a dry run, with real-time output. On `sync:backups-clean`, preview which backups would be deleted. |
| `-A`, `--all` | Sync every configured recipe. On `sync:backups-clean`, delete every backup. |
| `-B`, `--backup` | Back up local files to `backup_dir` before a real pull. |
| `-F`, `--force` | `sync:backups-clean` only. Skip the confirmation prompt. |
| `-K`, `--keep=` | `sync:backups-clean` only. Keep the N newest backups, deleting the rest. |
| `--older-than=` | `sync:backups-clean` only. Delete backups older than N days. |
| `-v` | Show real-time output while syncing (progress, stats, ...). |

Any argument you omit is prompted for interactively (operation, remote, recipes, and rsync options), unless
you pass `--no-interaction`, in which case a missing required value fails fast with a clear error instead of
prompting — and any real (non-dry) sync runs immediately without a confirmation prompt.

Use `--dry` for a dry run, not `--option=--dry-run` — only `--dry` skips the confirmation prompt, forces
real-time output, and reports it as a dry run instead of a completed sync.

`--backup` only applies to a real (non-dry) pull — a pull is the only operation that overwrites your local
files, so a push (or a dry run) ignores it. Before the pull runs, the local files of the selected recipes
are copied into a timestamped folder under `backup_dir` (e.g. `.sync-backups/2026-07-24_134530/`), using a
fixed `--archive --relative` copy independent of your chosen rsync options. If you don't pass `--backup` and
you're pulling interactively, you're asked whether to back up before you're asked which rsync options to use.

### Cleaning Up Backups

`sync:backups-clean` deletes timestamped folders under `backup_dir`, leaving `backup_dir` itself (and
anything in it that isn't a timestamped backup folder) untouched. Run it without options to pick backups
from an interactive list (with size and age), or pass `--all` to select every one. Add `--dry` to preview
what would be deleted without deleting anything, and `--force` to skip the confirmation prompt.

Running it with `--no-interaction` and without `--all` or a retention option fails fast with a friendly error
instead of deleting anything — there's no picker to fall back to, and deleting every backup by default would
be surprising. The confirmation prompt only appears when running interactively, so `--no-interaction --all`
(e.g. in a cron job) deletes immediately without needing `--force`.

Pass `--keep=N` and/or `--older-than=N` (days) to select backups by retention criteria instead of picking or
`--all` — the only selection method that works non-interactively without `--all`, making it the one to use in
a cron job. `--keep=N` deletes everything but the N newest; `--older-than=N` deletes anything older than N
days; combined, `--older-than` picks the candidates and `--keep` still protects the N newest among them, even
if they're also older than the cutoff. Combining either with `--all` is rejected, since `--all` already
selects every backup. `--older-than` is capped at 36500 days (~100 years) — comfortably beyond any real
retention window, and refused outright rather than risking day-arithmetic overflow silently deleting
everything instead of nothing.

### Testing a Connection

`sync:test-connection` authenticates to a remote over SSH and confirms its `root` path exists, without
syncing anything — useful for catching a misconfigured remote (or a broken SSH setup) before a real sync fails
partway through with an opaque `rsync` error. A local remote (no `user`/`host`) reports success immediately,
without opening any connection.

### Concurrency

Two `sync` runs against the same remote can't overlap — the second fails immediately rather than racing the
first. Nothing to configure; the lock always releases when the run ends.

## Examples

```bash
# Pull the "assets" recipe from "staging"
php artisan sync pull staging assets

# Push "assets" to "production" with custom rsync options
php artisan sync push production assets --option=-avh --option=--delete

# Preview a pull as a dry run, with real-time output
php artisan sync pull staging assets --dry

# Back up local "assets" files before pulling
php artisan sync pull staging assets --backup

# Sync every recipe
php artisan sync push production --all

# Preview what would run, without syncing
php artisan sync:list pull staging assets
php artisan sync:commands pull staging assets

# Check the SSH connection and root path for a remote before syncing
php artisan sync:test-connection staging

# Fully interactive
php artisan sync

# Pick which backups to delete from an interactive list
php artisan sync:backups-clean

# Delete every backup without a confirmation prompt
php artisan sync:backups-clean --all --force

# Preview which backups --all would delete
php artisan sync:backups-clean --all --dry

# Cron-safe cleanup: keep the 5 newest backups, delete anything else older than 30 days
php artisan sync:backups-clean --keep=5 --older-than=30 --no-interaction
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Sync! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [VITAMIN 2](https://vitamin2.ch)
- [All Contributors](../../contributors)

## License

Laravel Sync is open-sourced software licensed under the [MIT license](LICENSE.md).
