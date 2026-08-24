<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Vitamin2\Sync\Data\BackupFolder;
use Vitamin2\Sync\Rsync\RestoreCommand;

beforeEach(function () {
    $this->backup = BackupFolder::fromPath(base_path('.sync-backups/2026-07-24_134530'), 0);
});

it('restores the backup folder\'s contents onto the project root', function () {
    $command = new RestoreCommand($this->backup);

    expect($command->origin())->toBe(base_path('.sync-backups/2026-07-24_134530').'/')
        ->and($command->target())->toBe(base_path().'/');
});

it('builds an argument list without a dry-run flag by default', function () {
    $command = new RestoreCommand($this->backup);

    expect($command->toArgs())->toBe([
        'rsync',
        '--archive',
        '--itemize-changes',
        base_path('.sync-backups/2026-07-24_134530').'/',
        base_path().'/',
    ]);
});

it('adds --dry-run when restoring as a dry run', function () {
    $command = new RestoreCommand($this->backup, dry: true);

    expect($command->toArgs())->toBe([
        'rsync',
        '--archive',
        '--itemize-changes',
        '--dry-run',
        base_path('.sync-backups/2026-07-24_134530').'/',
        base_path().'/',
    ]);
});

it('adds --delete when mirroring, before --dry-run', function () {
    $command = new RestoreCommand($this->backup, dry: true, mirror: true);

    expect($command->toArgs())->toBe([
        'rsync',
        '--archive',
        '--itemize-changes',
        '--delete',
        '--dry-run',
        base_path('.sync-backups/2026-07-24_134530').'/',
        base_path().'/',
    ]);
});

it('omits --delete by default, even when built with named arguments', function () {
    $command = new RestoreCommand($this->backup, dry: false, mirror: false);

    expect($command->toArgs())->not->toContain('--delete');
});

it('scopes a directory entry to a trailing-slash content merge, both sides', function () {
    $backupDir = base_path('.sync-backups-'.Str::random(8));
    File::ensureDirectoryExists("{$backupDir}/2026-07-24_134530/storage/app");

    try {
        $backup = BackupFolder::fromPath("{$backupDir}/2026-07-24_134530", 0);
        $command = new RestoreCommand($backup, mirror: true, entry: 'storage');

        expect($command->origin())->toBe("{$backupDir}/2026-07-24_134530/storage/")
            ->and($command->target())->toBe(base_path('storage').'/')
            ->and($command->toArgs())->toBe([
                'rsync',
                '--archive',
                '--itemize-changes',
                '--delete',
                "{$backupDir}/2026-07-24_134530/storage/",
                base_path('storage').'/',
            ]);
    } finally {
        File::deleteDirectory($backupDir);
    }
});

it('scopes a file entry to an exact copy, no trailing slash on either side', function () {
    $backupDir = base_path('.sync-backups-'.Str::random(8));
    File::ensureDirectoryExists("{$backupDir}/2026-07-24_134530");
    File::put("{$backupDir}/2026-07-24_134530/composer.json", '{}');

    try {
        $backup = BackupFolder::fromPath("{$backupDir}/2026-07-24_134530", 0);
        $command = new RestoreCommand($backup, mirror: true, entry: 'composer.json');

        expect($command->origin())->toBe("{$backupDir}/2026-07-24_134530/composer.json")
            ->and($command->target())->toBe(base_path('composer.json'));
    } finally {
        File::deleteDirectory($backupDir);
    }
});

it('collapses a trailing slash on the backup folder path instead of doubling it', function () {
    $backup = BackupFolder::fromPath(base_path('.sync-backups/2026-07-24_134530/'), 0);
    $command = new RestoreCommand($backup);

    expect($command->origin())->toBe(base_path('.sync-backups/2026-07-24_134530').'/');
});
