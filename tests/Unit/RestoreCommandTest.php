<?php

declare(strict_types=1);

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

it('collapses a trailing slash on the backup folder path instead of doubling it', function () {
    $backup = BackupFolder::fromPath(base_path('.sync-backups/2026-07-24_134530/'), 0);
    $command = new RestoreCommand($backup);

    expect($command->origin())->toBe(base_path('.sync-backups/2026-07-24_134530').'/');
});
