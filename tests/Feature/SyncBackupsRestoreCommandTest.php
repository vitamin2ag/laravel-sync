<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Vitamin2\Sync\Data\BackupFolder;
use Vitamin2\Sync\Sync;

beforeEach(function () {
    Process::fake();

    // Unique per test (even under `pest --parallel`, which shares one Testbench
    // skeleton across the run) so these filesystem-touching tests can't collide.
    config(['sync.backup_dir' => $backupDir = '.sync-backups-'.Str::random(8)]);

    $this->backupDir = $backupDir;
    $this->backupPath = base_path($backupDir);
});

afterEach(function () {
    File::deleteDirectory($this->backupPath);
});

it('reports no backups found when the backup directory is empty', function () {
    $this->artisan('sync:backups-restore', ['--no-interaction' => true])
        ->expectsOutputToContain('No backups found')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('restores the given backup by name, with --no-interaction skipping both the argument prompt and the confirmation', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $this->artisan('sync:backups-restore', ['backup' => '2026-07-24_134530', '--no-interaction' => true])
        ->expectsOutputToContain('Restored backup "2026-07-24_134530" onto the project root.')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('--archive', $process->command, true)
        && in_array("{$this->backupPath}/2026-07-24_134530/", $process->command, true)
        && in_array(base_path().'/', $process->command, true));
});

it('fails with a friendly error for an explicit backup name when the backup directory is empty, instead of silently succeeding', function () {
    // The backup_dir being entirely empty must not shortcut past an explicitly named
    // (and thus unknown) backup — a script running `sync:backups-restore missing
    // --no-interaction` should see this failure, not a misleading exit code 0.
    $this->artisan('sync:backups-restore', ['backup' => 'missing', '--no-interaction' => true])
        ->expectsOutputToContain('The backup "missing" was not found.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails with a friendly error for an unknown backup name', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $this->artisan('sync:backups-restore', ['backup' => 'unknown', '--no-interaction' => true])
        ->expectsOutputToContain('The backup "unknown" was not found.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails with a friendly error when no backup is given and cannot be prompted for', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $this->artisan('sync:backups-restore', ['--no-interaction' => true])
        ->expectsOutputToContain('You must specify a backup to restore.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails fast on an explicit empty-string backup argument, even when interactive, instead of prompting', function () {
    // Matches ResolvesRemote::resolveArgumentOrPrompt()'s established shape: a prompt
    // only fires when the argument is missing outright, not merely blank — an explicit
    // empty string always fails fast with the same friendly error, interactive or not.
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $this->artisan('sync:backups-restore', ['backup' => ''])
        ->expectsOutputToContain('You must specify a backup to restore.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('prompts for the backup to restore, then confirms, when run interactively', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $backup = resolve(Sync::class)->backups()->sole();

    $this->artisan('sync:backups-restore')
        ->expectsChoice(
            'Which backup do you want to restore?',
            $backup->name,
            [$backup->name => $backup->label()],
        )
        ->expectsConfirmation(
            'You are about to restore backup "2026-07-24_134530" onto your project. Existing files may be overwritten. Are you sure?',
            'yes',
        )
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
});

it('aborts when the confirmation is declined', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $this->artisan('sync:backups-restore', ['backup' => '2026-07-24_134530'])
        ->expectsConfirmation(
            'You are about to restore backup "2026-07-24_134530" onto your project. Existing files may be overwritten. Are you sure?',
            'no',
        )
        ->expectsOutputToContain('Restore aborted.')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('skips the confirmation prompt with --force', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $this->artisan('sync:backups-restore', ['backup' => '2026-07-24_134530', '--force' => true])
        ->doesntExpectOutputToContain('Existing files may be overwritten')
        ->expectsOutputToContain('Restored backup "2026-07-24_134530" onto the project root.')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
});

it('skips the confirmation prompt on a dry run, without needing --force', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $this->artisan('sync:backups-restore', ['backup' => '2026-07-24_134530', '--dry' => true])
        ->doesntExpectOutputToContain('Existing files may be overwritten')
        ->expectsOutputToContain('Dry run completed successfully. Nothing was restored.')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('--dry-run', $process->command, true));
});

it('reports a distinct error when the restore process fails', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    Process::fake(fn () => Process::result(exitCode: 1));

    $this->artisan('sync:backups-restore', ['backup' => '2026-07-24_134530', '--force' => true])
        ->expectsOutputToContain('Restore failed.')
        ->assertFailed();
});

it('reports a distinct dry-run failure message when the dry run process fails', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    Process::fake(fn () => Process::result(exitCode: 1));

    $this->artisan('sync:backups-restore', ['backup' => '2026-07-24_134530', '--dry' => true])
        ->expectsOutputToContain('Dry run failed.')
        ->assertFailed();
});

it('refuses to restore a backup folder that has been replaced by a symlink since it was listed', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $backup = resolve(Sync::class)->backups()->sole();

    $realBackup = BackupFolder::fromPath($backup->path, $backup->size);
    File::deleteDirectory($backup->path);

    // Not `File::link()`: creating a symlink needs a privilege CI's Windows runners
    // don't grant by default, so this skips (rather than failing for an unrelated
    // reason) wherever the environment can't actually create one.
    if (! @symlink(base_path(), $backup->path)) {
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    try {
        $result = resolve(Sync::class)->restoreBackup($realBackup, dry: false);

        expect($result)->toBeFalse();

        Process::assertNothingRan();
    } finally {
        @unlink($backup->path);
    }
});
