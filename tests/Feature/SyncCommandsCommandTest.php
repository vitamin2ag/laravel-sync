<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Vitamin2\Sync\Rsync\RsyncOptions;

beforeEach(function () {
    config([
        'sync.remotes' => [
            'staging' => ['user' => 'forge', 'host' => '5.6.7.8', 'root' => '/srv/staging'],
        ],
        'sync.recipes' => [
            'assets' => ['storage/app/assets/'],
        ],
        'sync.options' => ['--archive'],
    ]);
});

it('prints the rsync command for a push without running it', function () {
    $this->artisan('sync:commands', ['operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain(sprintf(
            "rsync -e 'ssh -p 22 -o StrictHostKeyChecking=accept-new' --archive %s forge@5.6.7.8:/srv/staging/storage/app/assets/",
            base_path('storage/app/assets/'),
        ))
        ->assertSuccessful();
});

it('reverses origin and target for a pull', function () {
    $this->artisan('sync:commands', ['operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain(sprintf(
            "rsync -e 'ssh -p 22 -o StrictHostKeyChecking=accept-new' --archive forge@5.6.7.8:/srv/staging/storage/app/assets/ %s",
            base_path('storage/app/assets/'),
        ))
        ->assertSuccessful();
});

it('fails with a friendly error for an unknown remote', function () {
    $this->artisan('sync:commands', ['operation' => 'push', 'remote' => 'unknown', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('The remote "unknown" is not defined in your config/sync.php file.')
        ->assertFailed();
});

it('prints the backup command before the pull command when --backup is passed', function () {
    $this->travelTo(Date::parse('2026-07-24 13:45:30'));

    $this->artisan('sync:commands', [
        'operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--backup' => true, '--no-interaction' => true,
    ])
        ->expectsOutputToContain(sprintf(
            '(cd %s && rsync --archive --relative storage/app/assets/ %s/)',
            base_path(),
            base_path('.sync-backups/2026-07-24_134530'),
        ))
        ->expectsOutputToContain(sprintf(
            "rsync -e 'ssh -p 22 -o StrictHostKeyChecking=accept-new' --archive forge@5.6.7.8:/srv/staging/storage/app/assets/ %s",
            base_path('storage/app/assets/'),
        ))
        ->assertSuccessful();
});

it('prints no backup command for a push, even with --backup passed', function () {
    $this->artisan('sync:commands', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--backup' => true, '--no-interaction' => true,
    ])
        ->doesntExpectOutputToContain('--relative')
        ->assertSuccessful();
});

it('never asks to back up interactively, since this command only previews', function () {
    $this->artisan('sync:commands', ['operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets']])
        ->expectsChoice('Which rsync options do you want to use?', ['--archive'], RsyncOptions::AVAILABLE)
        ->assertSuccessful();
});
