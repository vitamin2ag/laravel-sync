<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Vitamin2\Sync\Data\Backup;
use Vitamin2\Sync\Data\Recipe;
use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Enums\Operation;
use Vitamin2\Sync\PendingSync;
use Vitamin2\Sync\Rsync\RsyncOptions;

beforeEach(function () {
    $this->remote = Remote::fromArray('production', ['user' => 'forge', 'host' => '1.2.3.4', 'root' => '/srv/app']);
});

it('builds one command per unique recipe path', function () {
    $recipes = collect([
        new Recipe('assets', ['storage/app/assets/', 'storage/app/img/']),
        new Recipe('env', ['storage/app/assets/', '.env']),
    ]);

    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    expect($pending->commands()->map->path->all())->toBe([
        'storage/app/assets/',
        'storage/app/img/',
        '.env',
    ]);
});

it('appends a recipe\'s own excludes to that recipe\'s rsync commands', function () {
    $recipes = collect([
        new Recipe('assets', ['storage/app/assets/'], ['*.log']),
        new Recipe('env', ['.env']),
    ]);

    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));
    $commands = $pending->commands()->keyBy->path;

    expect($commands['storage/app/assets/']->options->flags)->toBe(['--archive', '--exclude=*.log'])
        ->and($commands['.env']->options->flags)->toBe(['--archive']);
});

it('appends a recipe\'s own excludes-from files to that recipe\'s rsync commands', function () {
    $recipes = collect([
        new Recipe('assets', ['storage/app/assets/'], [], ['.rsync-excludes']),
        new Recipe('env', ['.env']),
    ]);

    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));
    $commands = $pending->commands()->keyBy->path;

    expect($commands['storage/app/assets/']->options->flags)->toBe([
        '--archive', '--exclude-from='.base_path('.rsync-excludes'),
    ])
        ->and($commands['.env']->options->flags)->toBe(['--archive']);
});

it('merges excludes-from files from every recipe that shares a path', function () {
    $recipes = collect([
        new Recipe('assets', ['storage/app/assets/'], [], ['.rsync-excludes-a']),
        new Recipe('assets-again', ['storage/app/assets/'], [], ['.rsync-excludes-b']),
    ]);

    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    expect($pending->commands()->sole()->options->flags)->toBe([
        '--archive',
        '--exclude-from='.base_path('.rsync-excludes-a'),
        '--exclude-from='.base_path('.rsync-excludes-b'),
    ]);
});

it('does not apply recipe excludes-from files to the backup pass', function () {
    $recipes = collect([new Recipe('assets', ['storage/app/assets/'], [], ['.rsync-excludes'])]);

    $pending = new PendingSync(
        Operation::Pull,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->backups()->sole()->toArgs())->not->toContain('--exclude-from='.base_path('.rsync-excludes'));
});

it('handles a purely numeric recipe path without PHP coercing it to an array key', function () {
    // "123" is the shape PHP coerces to an int array key — the bug under test. Looked up
    // via firstWhere(), not keyBy(), which would hit that same coercion in the test itself.
    $recipes = collect([
        new Recipe('releases', ['123', '124']),
        new Recipe('releases-again', ['123'], ['*.log']),
    ]);

    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));
    $commands = $pending->commands();

    expect($commands->map->path->all())->toBe(['123', '124'])
        ->and($commands->firstWhere('path', '123')->options->flags)->toBe(['--archive', '--exclude=*.log'])
        ->and($commands->firstWhere('path', '124')->options->flags)->toBe(['--archive']);
});

it('merges excludes from every recipe that shares a path', function () {
    $recipes = collect([
        new Recipe('assets', ['storage/app/assets/'], ['*.log']),
        new Recipe('assets-again', ['storage/app/assets/'], ['node_modules/']),
    ]);

    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    expect($pending->commands()->sole()->options->flags)->toBe([
        '--archive', '--exclude=*.log', '--exclude=node_modules/',
    ]);
});

it('does not apply recipe excludes to the backup pass', function () {
    $recipes = collect([new Recipe('assets', ['storage/app/assets/'], ['*.log'])]);

    $pending = new PendingSync(
        Operation::Pull,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->backups()->sole()->toArgs())->not->toContain('--exclude=*.log');
});

it('runs one process per resolved command', function () {
    Process::fake();

    $recipes = collect([new Recipe('assets', ['storage/app/assets/', 'storage/app/img/'])]);
    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    $pending->run();

    Process::assertRanTimes(fn ($process) => true, 2);
    Process::assertRan(fn ($process) => in_array(base_path('storage/app/assets/'), $process->command, true));
    Process::assertRan(fn ($process) => in_array(base_path('storage/app/img/'), $process->command, true));
});

it('runs each command as an argument list instead of a shell string', function () {
    Process::fake();

    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    $pending->run();

    Process::assertRan(fn ($process) => is_array($process->command) && $process->command[0] === 'rsync');
});

it('returns true when every command succeeds', function () {
    Process::fake();

    $recipes = collect([new Recipe('assets', ['storage/app/assets/', 'storage/app/img/'])]);
    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    expect($pending->run())->toBeTrue();
});

it('returns false when any command fails', function () {
    Process::fake(fn ($process) => in_array(base_path('storage/app/img/'), $process->command, true)
        ? Process::result(exitCode: 1)
        : Process::result());

    $recipes = collect([new Recipe('assets', ['storage/app/assets/', 'storage/app/img/'])]);
    $pending = new PendingSync(Operation::Push, $this->remote, $recipes, new RsyncOptions(['--archive']));

    expect($pending->run())->toBeFalse();

    Process::assertRanTimes(fn ($process) => true, 2);
});

it('builds no backup commands without a backup', function () {
    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(Operation::Pull, $this->remote, $recipes, new RsyncOptions(['--archive']));

    expect($pending->backups())->toBeEmpty();
});

it('builds no backup commands for a push, even with a backup requested', function () {
    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(
        Operation::Push,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->backups())->toBeEmpty();
});

it('normalizes a backup requested for a push to null, so backup !== null reliably implies one runs', function () {
    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(
        Operation::Push,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->backup)->toBeNull();
});

it('builds one backup command per unique recipe path on a pull with a backup requested', function () {
    $recipes = collect([new Recipe('assets', ['storage/app/assets/', 'storage/app/img/'])]);
    $pending = new PendingSync(
        Operation::Pull,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->backups()->map->path->all())->toBe(['storage/app/assets/', 'storage/app/img/']);
});

it('runs the backup before the sync, then the sync commands', function () {
    Process::fake();

    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(
        Operation::Pull,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->run())->toBeTrue();

    Process::assertRanTimes(fn ($process) => true, 2);
    Process::assertRan(fn ($process) => in_array('--relative', $process->command, true)
        && in_array('storage/app/assets/', $process->command, true)
        && $process->path === base_path());
    Process::assertRan(fn ($process) => in_array(
        'forge@1.2.3.4:/srv/app/storage/app/assets/',
        $process->command,
        true,
    ));
});

it('aborts before the pull when the backup fails', function () {
    Process::fake(fn ($process) => in_array('--relative', $process->command, true)
        ? Process::result(exitCode: 1)
        : Process::result());

    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(
        Operation::Pull,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->run())->toBeFalse();

    Process::assertRanTimes(fn ($process) => true, 1);
    Process::assertNotRan(fn ($process) => in_array(
        'forge@1.2.3.4:/srv/app/storage/app/assets/',
        $process->command,
        true,
    ));
});

it('runs only the backup via runBackup()', function () {
    Process::fake();

    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(
        Operation::Pull,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->runBackup())->toBeTrue();

    Process::assertRanTimes(fn ($process) => true, 1);
    Process::assertRan(fn ($process) => in_array('--relative', $process->command, true));
});

it('runs only the sync via runSync(), without backing up', function () {
    Process::fake();

    $recipes = collect([new Recipe('assets', ['storage/app/assets/'])]);
    $pending = new PendingSync(
        Operation::Pull,
        $this->remote,
        $recipes,
        new RsyncOptions(['--archive']),
        new Backup('.sync-backups', '2026-07-24_134530'),
    );

    expect($pending->runSync())->toBeTrue();

    Process::assertRanTimes(fn ($process) => true, 1);
    Process::assertNotRan(fn ($process) => in_array('--relative', $process->command, true));
});
