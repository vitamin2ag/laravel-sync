<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Vitamin2\Sync\Rsync\RsyncOptions;
use Vitamin2\Sync\Sync;

beforeEach(function () {
    Process::fake();

    config([
        'sync.remotes' => [
            'production' => ['user' => 'forge', 'host' => '1.2.3.4', 'root' => '/srv/app', 'read_only' => true],
            'staging' => ['user' => 'forge', 'host' => '5.6.7.8', 'root' => '/srv/staging'],
        ],
        'sync.recipes' => [
            'assets' => ['storage/app/assets/'],
            'env' => ['.env'],
        ],
        'sync.options' => ['--archive'],
    ]);
});

it('runs a dry sync without asking for confirmation', function () {
    $this->artisan('sync', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--dry' => true, '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Dry run completed successfully.')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
    Process::assertRan(fn ($process) => in_array('--dry-run', $process->command, true)
        && in_array(base_path('storage/app/assets/'), $process->command, true));
});

it('runs a real sync without confirmation when not interactive', function () {
    $this->artisan('sync', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Sync completed successfully.')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
});

it('falls back to config default options when --option is passed an empty string', function () {
    $this->artisan('sync', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--option' => [''], '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('--archive', $process->command, true));
});

it('fails when the underlying rsync process fails', function () {
    Process::fake(fn () => Process::result(exitCode: 1));

    $this->artisan('sync', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Sync failed.')
        ->assertFailed();
});

it('syncs every recipe with --all', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', '--all' => true, '--no-interaction' => true])
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 2);
});

it('fails with a friendly error for an invalid operation instead of crashing', function () {
    $this->artisan('sync', ['operation' => 'sideways', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('Invalid operation "sideways". Expected "push" or "pull".')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails with a friendly error when the operation is missing and cannot be prompted for', function () {
    $this->artisan('sync', ['remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('You must specify an operation: "push" or "pull".')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails with a friendly error when the remote is missing and cannot be prompted for', function () {
    $this->artisan('sync', ['operation' => 'push', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('You must specify a remote.')
        ->assertFailed();
});

it('fails with a friendly error for an unknown remote', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'unknown', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('The remote "unknown" is not defined in your config/sync.php file.')
        ->assertFailed();
});

it('fails with a friendly error for an unknown recipe', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', 'recipe' => ['unknown'], '--no-interaction' => true])
        ->expectsOutputToContain('The recipe "unknown" is not defined in your config/sync.php file.')
        ->assertFailed();
});

it('fails with a friendly error when no recipes are configured', function () {
    config(['sync.recipes' => []]);

    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', '--all' => true, '--no-interaction' => true])
        ->expectsOutputToContain('You need to define at least one recipe in your config/sync.php file.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails when no recipe is given and --all is not passed', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', '--no-interaction' => true])
        ->expectsOutputToContain('You must select at least one recipe, or pass --all to sync every recipe.')
        ->assertFailed();
});

it('refuses to sync a path with itself', function () {
    config(['sync.remotes' => array_merge(config('sync.remotes'), [
        'here' => ['root' => base_path()],
    ])]);

    $this->artisan('sync', ['operation' => 'push', 'remote' => 'here', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('The origin and target path for "storage/app/assets/" are the same. Refusing to sync a path with itself.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails with a friendly error when a recipe\'s excludes_from file does not exist', function () {
    config(['sync.excludes_from' => ['assets' => ['storage/app/.rsync-excludes-missing']]]);

    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('The excludes_from file "storage/app/.rsync-excludes-missing" configured for recipe "assets" does not exist.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('passes a recipe\'s excludes_from file to rsync as an absolute --exclude-from path', function () {
    config(['sync.excludes_from' => ['assets' => ['storage/app/.rsync-excludes']]]);
    File::put(base_path('storage/app/.rsync-excludes'), '*.log');

    try {
        $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--no-interaction' => true])
            ->assertSuccessful();

        Process::assertRan(fn ($process) => in_array('--exclude-from='.base_path('storage/app/.rsync-excludes'), $process->command, true));
    } finally {
        File::delete(base_path('storage/app/.rsync-excludes'));
    }
});

it('refuses to push to a read-only remote', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'production', 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain('The remote "production" is read-only and cannot be pushed to.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('allows pulling from a read-only remote', function () {
    $this->artisan('sync', ['operation' => 'pull', 'remote' => 'production', 'recipe' => ['assets'], '--no-interaction' => true])
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
});

it('backs up local files before a real pull when --backup is passed', function () {
    $this->travelTo(Date::parse('2026-07-24 13:45:30'));

    $this->artisan('sync', [
        'operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--backup' => true, '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Backing up local files...')
        ->expectsOutputToContain('Sync completed successfully.')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 2);
    Process::assertRan(fn ($process) => in_array('--relative', $process->command, true)
        && in_array('storage/app/assets/', $process->command, true)
        && in_array(base_path('.sync-backups/2026-07-24_134530').'/', $process->command, true)
        && $process->path === base_path());
});

it('reports a distinct error when the backup fails, and never runs the pull', function () {
    Process::fake(fn ($process) => in_array('--relative', $process->command, true)
        ? Process::result(exitCode: 1)
        : Process::result());

    $this->artisan('sync', [
        'operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--backup' => true, '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Backing up local files...')
        ->expectsOutputToContain('Backup failed. Nothing was synced — your local files are untouched.')
        ->assertFailed();

    Process::assertRanTimes(fn ($process) => true, 1);
    Process::assertNotRan(fn ($process) => in_array('forge@5.6.7.8:/srv/staging/storage/app/assets/', $process->command, true));
});

it('does not back up on a dry pull, even with --backup passed', function () {
    $this->artisan('sync', [
        'operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--backup' => true, '--dry' => true, '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
    Process::assertRan(fn ($process) => in_array('--dry-run', $process->command, true));
    Process::assertNotRan(fn ($process) => in_array('--relative', $process->command, true));
});

it('ignores --backup on a push', function () {
    $this->artisan('sync', [
        'operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '--backup' => true, '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
    Process::assertNotRan(fn ($process) => in_array('--relative', $process->command, true));
});

it('fails with a friendly error when the backup directory is nested inside a recipe path', function () {
    config(['sync.backup_dir' => 'storage/app/assets/.sync-backups']);

    $this->artisan('sync', [
        'operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--backup' => true, '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Choose a backup_dir outside the recipe paths you back up.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails with a friendly error when the backup directory resolves outside the project', function () {
    config(['sync.backup_dir' => '../outside']);

    $this->artisan('sync', [
        'operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--backup' => true, '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Set a backup_dir inside your project.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('strips rsync\'s own backup flags from the pull command when --backup is passed', function () {
    $this->artisan('sync', [
        'operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'],
        '--backup' => true, '--option' => ['--archive', '--backup', '--backup-dir=/tmp/old'], '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('forge@5.6.7.8:/srv/staging/storage/app/assets/', $process->command, true)
        && ! in_array('--backup', $process->command, true)
        && ! in_array('--backup-dir=/tmp/old', $process->command, true));
});

it('aborts when the user declines the confirmation prompt', function () {
    $this->artisan('sync', ['operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets'], '--option' => ['--archive']])
        ->expectsConfirmation('Back up the local files before pulling?', 'no')
        ->expectsConfirmation('You are about to pull "assets" from "staging". Are you sure?')
        ->expectsOutputToContain('Sync aborted.')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('asks whether to back up before asking which options to use, interactively, on a pull', function () {
    // "--backup" is dropped from the options prompt, since the confirmed backup already covers it.
    $expectedOptions = collect(RsyncOptions::AVAILABLE)->except('--backup')->all();

    $this->artisan('sync', ['operation' => 'pull', 'remote' => 'staging', 'recipe' => ['assets']])
        ->expectsConfirmation('Back up the local files before pulling?', 'yes')
        ->expectsChoice('Which rsync options do you want to use?', ['--archive'], $expectedOptions)
        ->expectsConfirmation('You are about to pull "assets" from "staging". Are you sure?', 'yes')
        ->expectsOutputToContain('Backing up local files...')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 2);
});

it('syncs every recipe when the user confirms "sync all recipes?"', function () {
    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', '--option' => ['--archive']])
        ->expectsConfirmation('Sync all recipes?', 'yes')
        ->expectsConfirmation('You are about to push "assets and env" to "staging". Are you sure?', 'yes')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 2);
});

it('prompts for anything missing, interactively, before syncing', function () {
    $this->artisan('sync')
        ->expectsChoice('Which operation do you want to perform?', 'push', ['push' => 'Push', 'pull' => 'Pull'])
        ->expectsChoice('Which remote do you want to sync with?', 'staging', ['production', 'staging'])
        ->expectsConfirmation('Sync all recipes?')
        ->expectsChoice('Which recipes do you want to sync?', ['assets'], ['assets', 'env'])
        ->expectsChoice('Which rsync options do you want to use?', ['--archive'], RsyncOptions::AVAILABLE)
        ->expectsConfirmation('You are about to push "assets" to "staging". Are you sure?', 'yes')
        ->expectsOutputToContain('Sync completed successfully.')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
});

it('moves the configured default options to the front of the prompt, in AVAILABLE order', function () {
    // Config lists "--archive" second, to prove sorting follows AVAILABLE's order, not the config's.
    config(['sync.options' => ['--verbose', '--archive']]);

    $expectedOrder = [
        '--archive', '--verbose', '--delete', '--progress', '--compress', '--stats',
        '--human-readable', '--itemize-changes', '--update', '--partial', '--delete-after',
        '--checksum', '--copy-links', '--no-perms', '--no-owner', '--no-group', '--backup',
    ];
    $expectedOptions = collect($expectedOrder)
        ->mapWithKeys(fn (string $flag) => [$flag => RsyncOptions::AVAILABLE[$flag]])
        ->all();

    $this->artisan('sync')
        ->expectsChoice('Which operation do you want to perform?', 'push', ['push' => 'Push', 'pull' => 'Pull'])
        ->expectsChoice('Which remote do you want to sync with?', 'staging', ['production', 'staging'])
        ->expectsConfirmation('Sync all recipes?')
        ->expectsChoice('Which recipes do you want to sync?', ['assets'], ['assets', 'env'])
        ->expectsChoice('Which rsync options do you want to use?', ['--verbose', '--archive'], $expectedOptions)
        ->expectsConfirmation('You are about to push "assets" to "staging". Are you sure?', 'yes')
        ->assertSuccessful();
});

it('excludes output-producing options from the prompt when -v is passed, since -v forces them on anyway', function () {
    // "--progress" is both a configured default and an output-producing flag, to prove it's
    // dropped from the prompt (and its defaults) rather than merely reordered.
    config(['sync.options' => ['--archive', '--progress']]);

    $expectedOptions = collect(RsyncOptions::AVAILABLE)
        ->reject(fn (string $label, string $flag) => in_array($flag, RsyncOptions::OUTPUT_PRODUCING, true))
        ->all();

    $this->artisan('sync', ['operation' => 'push', 'remote' => 'staging', 'recipe' => ['assets'], '-v' => true])
        ->expectsChoice('Which rsync options do you want to use?', ['--archive'], $expectedOptions)
        ->expectsConfirmation('You are about to push "assets" to "staging". Are you sure?', 'yes')
        ->assertSuccessful();

    // "--progress" still ends up on the actual rsync command, forced on by -v.
    Process::assertRan(fn ($process) => in_array('--progress', $process->command, true));
});

it('refuses to run when another sync is already in progress for the same remote', function () {
    // Unique root, not "staging": lock files are keyed by root and live under the real
    // storage_path(), shared across parallel test workers — a shared root would collide.
    config(['sync.remotes' => array_merge(config('sync.remotes'), [
        $name = 'locked-'.Str::random(8) => ['root' => base_path('storage/app/'.$name)],
    ])]);

    $remote = resolve(Sync::class)->remote($name);
    $lock = resolve(Sync::class)->lock($remote);
    $lock->acquire();

    $this->artisan('sync', ['operation' => 'push', 'remote' => $name, 'recipe' => ['assets'], '--no-interaction' => true])
        ->expectsOutputToContain(sprintf('Could not start a sync for "%s"', $name))
        ->assertFailed();

    Process::assertNothingRan();

    $lock->release();
    File::delete($lock->path);
});

it('releases the lock after a run, so a later sync against the same remote can proceed', function () {
    config(['sync.remotes' => array_merge(config('sync.remotes'), [
        $name = 'unlocked-'.Str::random(8) => ['root' => base_path('storage/app/'.$name)],
    ])]);

    $this->artisan('sync', ['operation' => 'push', 'remote' => $name, 'recipe' => ['assets'], '--no-interaction' => true])
        ->assertSuccessful();

    $lock = resolve(Sync::class)->lock(resolve(Sync::class)->remote($name));

    expect($lock->acquire())->toBeTrue();

    $lock->release();
    File::delete($lock->path);
});
