<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Vitamin2\Sync\Data\Backup;
use Vitamin2\Sync\Data\BackupFolder;
use Vitamin2\Sync\Data\Recipe;
use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Enums\Operation;
use Vitamin2\Sync\Exceptions\SyncException;
use Vitamin2\Sync\PendingSync;
use Vitamin2\Sync\Rsync\RsyncOptions;
use Vitamin2\Sync\Sync;

beforeEach(function () {
    config([
        'sync.remotes' => [
            'production' => ['user' => 'forge', 'host' => '1.2.3.4', 'root' => '/srv/app', 'read_only' => true],
            'staging' => ['user' => 'forge', 'host' => '5.6.7.8', 'root' => '/srv/staging'],
        ],
        'sync.recipes' => [
            'assets' => ['storage/app/assets/'],
        ],
        // Unique per test (even under `pest --parallel`, which shares one Testbench
        // skeleton across the run) so these filesystem-touching tests can't collide.
        'sync.backup_dir' => $backupDir = '.sync-backups-'.Str::random(8),
    ]);

    $this->backupDir = $backupDir;
    $this->backupPath = base_path($backupDir);
});

afterEach(function () {
    File::deleteDirectory($this->backupPath);
});

it('resolves the singleton from the container', function () {
    expect(resolve(Sync::class))->toBeInstanceOf(Sync::class)
        ->and(resolve(Sync::class))->toBe(resolve(Sync::class));
});

it('lists remotes hydrated from the config', function () {
    $remotes = resolve(Sync::class)->remotes();

    expect($remotes)->toBeInstanceOf(Collection::class)
        ->and($remotes->keys()->all())->toBe(['production', 'staging'])
        ->and($remotes->get('production'))->toBeInstanceOf(Remote::class);
});

it('throws when no remotes are configured', function () {
    config(['sync.remotes' => []]);

    resolve(Sync::class)->remotes();
})->throws(SyncException::class, 'You need to define at least one remote in your config/sync.php file.');

it('lists recipes hydrated from the config', function () {
    $recipes = resolve(Sync::class)->recipes();

    expect($recipes->keys()->all())->toBe(['assets'])
        ->and($recipes->get('assets'))->toBeInstanceOf(Recipe::class);
});

it('throws when no recipes are configured', function () {
    config(['sync.recipes' => []]);

    resolve(Sync::class)->recipes();
})->throws(SyncException::class, 'You need to define at least one recipe in your config/sync.php file.');

it('hydrates a recipe\'s excludes from the separate excludes config key, keyed by recipe name', function () {
    config(['sync.excludes' => ['assets' => ['*.log', 'node_modules/']]]);

    $recipe = resolve(Sync::class)->recipes()->get('assets');

    expect($recipe->excludes)->toBe(['*.log', 'node_modules/']);
});

it('defaults a recipe\'s excludes to an empty array when none are configured for it', function () {
    $recipe = resolve(Sync::class)->recipes()->get('assets');

    expect($recipe->excludes)->toBe([]);
});

it('resolves excludes for a recipe name containing a dot, without config() misreading it as a nested path', function () {
    config([
        'sync.recipes' => ['assets.images' => ['storage/app/img/']],
        'sync.excludes' => ['assets.images' => ['*.tmp']],
    ]);

    $recipe = resolve(Sync::class)->recipes()->get('assets.images');

    expect($recipe->excludes)->toBe(['*.tmp']);
});

it('hydrates a recipe\'s excludes-from files from the separate excludes_from config key, keyed by recipe name', function () {
    config(['sync.excludes_from' => ['assets' => ['.rsync-excludes']]]);

    $recipe = resolve(Sync::class)->recipes()->get('assets');

    expect($recipe->excludesFrom)->toBe(['.rsync-excludes']);
});

it('defaults a recipe\'s excludes-from files to an empty array when none are configured for it', function () {
    $recipe = resolve(Sync::class)->recipes()->get('assets');

    expect($recipe->excludesFrom)->toBe([]);
});

it('resolves excludes-from files for a recipe name containing a dot, without config() misreading it as a nested path', function () {
    config([
        'sync.recipes' => ['assets.images' => ['storage/app/img/']],
        'sync.excludes_from' => ['assets.images' => ['.rsync-excludes']],
    ]);

    $recipe = resolve(Sync::class)->recipes()->get('assets.images');

    expect($recipe->excludesFrom)->toBe(['.rsync-excludes']);
});

it('resolves a single remote by name', function () {
    expect(resolve(Sync::class)->remote('staging'))->toBeInstanceOf(Remote::class);
});

it('throws for an unknown remote', function () {
    resolve(Sync::class)->remote('unknown');
})->throws(SyncException::class, 'The remote "unknown" is not defined in your config/sync.php file.');

it('resolves a single recipe by name', function () {
    expect(resolve(Sync::class)->recipe('assets'))->toBeInstanceOf(Recipe::class);
});

it('throws for an unknown recipe', function () {
    resolve(Sync::class)->recipe('unknown');
})->throws(SyncException::class, 'The recipe "unknown" is not defined in your config/sync.php file.');

it('refuses to push to a read-only remote', function () {
    $sync = resolve(Sync::class);

    $sync->prepare(Operation::Push, $sync->remote('production'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throws(SyncException::class, 'The remote "production" is read-only and cannot be pushed to.');

it('allows pulling from a read-only remote', function () {
    $sync = resolve(Sync::class);

    $pending = $sync->prepare(Operation::Pull, $sync->remote('production'), collect([$sync->recipe('assets')]), new RsyncOptions([]));

    expect($pending)->toBeInstanceOf(PendingSync::class);
});

it('allows pushing to a remote that is not read-only', function () {
    $sync = resolve(Sync::class);

    $pending = $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));

    expect($pending)->toBeInstanceOf(PendingSync::class);
});

it('refuses to sync a path with itself', function () {
    config([
        'sync.remotes' => ['here' => ['root' => base_path()]],
        'sync.recipes' => ['assets' => ['storage/app/assets/']],
    ]);

    $sync = resolve(Sync::class);

    $sync->prepare(Operation::Push, $sync->remote('here'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throws(SyncException::class, 'The origin and target path for "storage/app/assets/" are the same. Refusing to sync a path with itself.');

it('refuses to sync a path with itself even when the remote root only differs by case', function () {
    config([
        'sync.remotes' => ['here' => ['root' => strtoupper(base_path())]],
        'sync.recipes' => ['assets' => ['storage/app/assets/']],
    ]);

    $sync = resolve(Sync::class);

    $sync->prepare(Operation::Push, $sync->remote('here'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throws(SyncException::class);

it('refuses an absolute unix-style recipe path', function () {
    config(['sync.recipes' => ['assets' => ['/etc/passwd']]]);

    $sync = resolve(Sync::class);

    $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throws(
    SyncException::class,
    'The path "/etc/passwd" in recipe "assets" is absolute. Recipe paths must be relative to the project root.',
);

it('refuses an absolute windows-style recipe path', function () {
    config(['sync.recipes' => ['assets' => ['C:/inetpub/wwwroot']]]);

    $sync = resolve(Sync::class);

    $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throws(
    SyncException::class,
    'The path "C:/inetpub/wwwroot" in recipe "assets" is absolute. Recipe paths must be relative to the project root.',
);

it('allows a recipe path that merely starts with a drive-letter-like prefix', function () {
    // "x:rules" is a relative POSIX filename, not a Windows-absolute path — only "x:/..."
    // (letter, colon, separator) is absolute.
    config(['sync.recipes' => ['assets' => ['x:rules']]]);

    $sync = resolve(Sync::class);

    $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throwsNoExceptions();

it('only checks recipe paths for the recipes being synced, not every configured recipe', function () {
    config([
        'sync.recipes' => [
            'assets' => ['storage/app/assets/'],
            'env' => ['/etc/passwd'],
        ],
    ]);

    $sync = resolve(Sync::class);

    $sync->guardRecipePathsAreRelative(collect([$sync->recipe('assets')]));
})->throwsNoExceptions();

it('allows a recipe whose excludes-from file exists', function () {
    $file = 'storage/app/.rsync-excludes-'.Str::random(8);
    config(['sync.excludes_from' => ['assets' => [$file]]]);
    File::put(base_path($file), '*.log');

    try {
        $sync = resolve(Sync::class);
        $pending = $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));

        expect($pending)->toBeInstanceOf(PendingSync::class);
    } finally {
        File::delete(base_path($file));
    }
});

it('finds a recipe\'s excludes-from file even when configured with Windows-style backslash separators', function () {
    // POSIX treats an unnormalized backslash path as one oddly-named segment, not a nested
    // path, so the guard and the `--exclude-from=` flag must both normalize it to "/".
    $name = '.rsync-excludes-'.Str::random(8);
    config(['sync.excludes_from' => ['assets' => ['storage\\app\\'.$name]]]);
    File::ensureDirectoryExists(base_path('storage/app'));
    File::put(base_path("storage/app/{$name}"), '*.log');

    try {
        $sync = resolve(Sync::class);
        $pending = $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));

        expect($pending)->toBeInstanceOf(PendingSync::class);
    } finally {
        File::delete(base_path("storage/app/{$name}"));
    }
});

it('allows a recipe\'s excludes-from file that is a symlink to a target outside the project', function () {
    // A shared `storage` symlinked out of the release directory is standard on
    // Envoyer-style deploys, so containment here would refuse an ordinary config value.
    $target = sys_get_temp_dir().'/sync-excludes-shared-'.Str::random(8);
    File::put($target, '*.log');

    $file = 'storage/app/.rsync-excludes-'.Str::random(8);
    $link = base_path($file);
    File::ensureDirectoryExists(base_path('storage/app'));

    if (! @symlink($target, $link)) {
        File::delete($target);
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    config(['sync.excludes_from' => ['assets' => [$file]]]);

    try {
        $sync = resolve(Sync::class);
        $pending = $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));

        expect($pending)->toBeInstanceOf(PendingSync::class);
    } finally {
        @unlink($link);
        File::delete($target);
    }
});

it('refuses to prepare a sync when a recipe\'s excludes-from file does not exist', function () {
    config(['sync.excludes_from' => ['assets' => ['storage/app/.rsync-excludes-missing']]]);

    $sync = resolve(Sync::class);

    $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throws(
    SyncException::class,
    'The excludes_from file "storage/app/.rsync-excludes-missing" configured for recipe "assets" does not exist.',
);

it('allows a recipe\'s excludes-from file reached through a ".." segment', function () {
    // Deliberately not confined to the project: a sibling checkout in a monorepo is a
    // legitimate place to keep a shared exclude list.
    $name = '.rsync-excludes-'.Str::random(8);
    File::put(dirname(base_path()).'/'.$name, '*.log');

    config(['sync.excludes_from' => ['assets' => ['../'.$name]]]);

    try {
        $sync = resolve(Sync::class);
        $pending = $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));

        expect($pending)->toBeInstanceOf(PendingSync::class);
    } finally {
        File::delete(dirname(base_path()).'/'.$name);
    }
});

it('allows a recipe\'s excludes-from file configured as an absolute path', function () {
    // `storage_path('app/.rsync-excludes')` in the config is idiomatic Laravel, and
    // `base_path()` would silently rebase it into a nonexistent path.
    $file = storage_path('app/.rsync-excludes-'.Str::random(8));
    File::ensureDirectoryExists(storage_path('app'));
    File::put($file, '*.log');

    config(['sync.excludes_from' => ['assets' => [$file]]]);

    try {
        $sync = resolve(Sync::class);
        $pending = $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));

        // Separator-normalized, since storage_path() yields backslashes on Windows — rsync
        // and PHP both accept "/" there, so the flag carries the normalized form.
        $expected = str_replace('\\', '/', $file);

        expect($pending->commands()->first()->toArgs())->toContain("--exclude-from={$expected}");
    } finally {
        File::delete($file);
    }
});

it('rebases a relative excludes-from path that merely starts with a drive-letter-like prefix', function () {
    // "x:rules" is a relative POSIX filename, not a Windows-absolute path — only "x:/..."
    // (letter, colon, separator) is absolute. Must not be misread as absolute and skip
    // rebasing under base_path().
    expect(Sync::resolveExcludesFromPath('x:rules'))->toBe(base_path('x:rules'));
});

it('reports an absolute excludes-from file that does not exist without rebasing it', function () {
    config(['sync.excludes_from' => ['assets' => ['/etc/.rsync-excludes-missing']]]);

    $sync = resolve(Sync::class);

    $sync->prepare(Operation::Push, $sync->remote('staging'), collect([$sync->recipe('assets')]), new RsyncOptions([]));
})->throws(
    SyncException::class,
    'The excludes_from file "/etc/.rsync-excludes-missing" configured for recipe "assets" does not exist.',
);

it('only checks excludes-from files for the recipes being synced, not every configured recipe', function () {
    config([
        'sync.recipes' => [
            'assets' => ['storage/app/assets/'],
            'env' => ['.env'],
        ],
        'sync.excludes_from' => ['env' => ['storage/app/.rsync-excludes-missing']],
    ]);

    $sync = resolve(Sync::class);

    $sync->guardExcludesFromFilesExist(collect([$sync->recipe('assets')]));
})->throwsNoExceptions();

it('refuses to back up when the backup directory is the recipe path itself', function () {
    $sync = resolve(Sync::class);
    $backup = new Backup('storage/app/assets', '2026-07-24_134530');

    $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );
})->throws(
    SyncException::class,
    'The backup directory "storage/app/assets" is the same as, or inside, the recipe path "storage/app/assets/". Choose a backup_dir outside the recipe paths you back up.',
);

it('refuses to back up when the backup directory is nested inside a recipe path', function () {
    $sync = resolve(Sync::class);
    $backup = new Backup('storage/app/assets/.sync-backups', '2026-07-24_134530');

    $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );
})->throws(SyncException::class);

it('refuses to back up when the backup directory only differs by case from the recipe path', function () {
    $sync = resolve(Sync::class);
    $backup = new Backup('STORAGE/APP/ASSETS', '2026-07-24_134530');

    $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );
})->throws(SyncException::class);

it('refuses to back up when a redundant ".." segment hides that the backup directory resolves inside a recipe path', function () {
    $sync = resolve(Sync::class);
    $backup = new Backup('storage/tmp/../app/assets/.sync-backups', '2026-07-24_134530');

    $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );
})->throws(SyncException::class);

it('refuses a Backup whose dir steps above the project root, even when guardBackupNotNested() is called directly', function () {
    // guardBackupNotNested() is public, not just an internal step of guardBackup()
    // (which always runs guardBackupDirSafe() first) — it must reject an escaping dir
    // on its own too, not rely on a caller having run the other guard first.
    $sync = resolve(Sync::class);
    $backup = new Backup('../../etc', '2026-07-24_134530');

    $sync->guardBackupNotNested($backup, collect([$sync->recipe('assets')]));
})->throws(SyncException::class);

it('allows backing up when the backup directory is outside the recipe paths', function () {
    $sync = resolve(Sync::class);
    $backup = new Backup('.sync-backups', '2026-07-24_134530');

    $pending = $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );

    expect($pending)->toBeInstanceOf(PendingSync::class);
});

it('does not guard against a nested backup directory on a push, since a push never backs up', function () {
    $sync = resolve(Sync::class);
    $backup = new Backup('storage/app/assets/.sync-backups', '2026-07-24_134530');

    $pending = $sync->prepare(
        Operation::Push,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );

    expect($pending)->toBeInstanceOf(PendingSync::class);
});

it('lists backup folders newest first', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-25_090000");

    $backups = resolve(Sync::class)->backups();

    expect($backups)->toBeInstanceOf(Collection::class)
        ->and($backups->pluck('name')->all())->toBe(['2026-07-25_090000', '2026-07-24_134530'])
        ->and($backups->first())->toBeInstanceOf(BackupFolder::class);
});

it('ignores folders whose name is not a backup timestamp', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    File::ensureDirectoryExists("{$this->backupPath}/not-a-backup");

    expect(resolve(Sync::class)->backups()->pluck('name')->all())->toBe(['2026-07-24_134530']);
});

it('ignores a folder that is digit-shaped but not an actual valid date', function () {
    // "2026-13-45_999999" has the right digit counts but rolls over to a different,
    // valid date when parsed — a loose "digit shape" check alone would wrongly accept it.
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    File::ensureDirectoryExists("{$this->backupPath}/2026-13-45_999999");

    expect(resolve(Sync::class)->backups()->pluck('name')->all())->toBe(['2026-07-24_134530']);
});

it('returns no backups when the backup directory does not exist', function () {
    expect(resolve(Sync::class)->backups())->toBeInstanceOf(Collection::class)
        ->and(resolve(Sync::class)->backups())->toHaveCount(0);
});

it('sums hidden files into a backup folder\'s size', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    File::put("{$this->backupPath}/2026-07-24_134530/visible.txt", str_repeat('a', 100));
    File::put("{$this->backupPath}/2026-07-24_134530/.env", str_repeat('b', 50));

    expect(resolve(Sync::class)->backups()->first()->size)->toBe(150);
});

it('ignores a symlinked folder even when its name is a valid backup timestamp', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    $target = sys_get_temp_dir().'/sync-outside-'.Str::random(8);
    File::ensureDirectoryExists($target);
    File::put("{$target}/important.txt", 'do not delete me');

    $linkPath = "{$this->backupPath}/2026-07-25_090000";

    if (! @symlink($target, $linkPath)) {
        File::deleteDirectory($target);
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    try {
        expect(resolve(Sync::class)->backups()->pluck('name')->all())->toBe(['2026-07-24_134530']);
    } finally {
        @unlink($linkPath);
        File::deleteDirectory($target);
    }
});

it('does not follow a symlink when summing a backup folder\'s size', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    File::put("{$this->backupPath}/2026-07-24_134530/visible.txt", str_repeat('a', 100));

    $target = sys_get_temp_dir().'/sync-outside-'.Str::random(8);
    File::ensureDirectoryExists($target);
    File::put("{$target}/huge.txt", str_repeat('b', 1000));

    $linkPath = "{$this->backupPath}/2026-07-24_134530/linked";

    if (! @symlink($target, $linkPath)) {
        File::deleteDirectory($target);
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    try {
        expect(resolve(Sync::class)->backups()->first()->size)->toBe(100);
    } finally {
        @unlink($linkPath);
        File::deleteDirectory($target);
    }
});

it('treats a glob metacharacter in backup_dir as a literal character, not a wildcard', function () {
    // "[" / "]" rather than "*" / "?", which are illegal in Windows filenames. Unescaped,
    // glob() reads "[literal]" as a bracket expression and finds nothing.
    $dir = 'glob-test-'.Str::random(8).'[literal]';

    File::ensureDirectoryExists(base_path("{$dir}/2026-07-24_134530"));

    try {
        config(['sync.backup_dir' => $dir]);

        expect(resolve(Sync::class)->backups()->pluck('name')->all())->toBe(['2026-07-24_134530']);
    } finally {
        File::deleteDirectory(base_path($dir));
    }
});

it('finds backups even when a redundant ".." segment in backup_dir points through a directory that does not exist', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");

    // "phantom-*" never exists on disk, so the raw string can't resolve through its own
    // "..". This finds anything only if backups() reads the dot-collapsed directory.
    $phantom = 'phantom-'.Str::random(8);
    config(['sync.backup_dir' => "{$phantom}/../{$this->backupDir}"]);

    expect(resolve(Sync::class)->backups()->pluck('name')->all())->toBe(['2026-07-24_134530']);
});

it('does not memoize the backup list, so it reflects a delete made earlier in the same run', function () {
    $sync = resolve(Sync::class);

    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    expect($sync->backups())->toHaveCount(1);

    File::deleteDirectory("{$this->backupPath}/2026-07-24_134530");
    expect($sync->backups())->toHaveCount(0);
});

it('deletes a backup folder and reports success', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    $folder = resolve(Sync::class)->backups()->sole();

    expect(resolve(Sync::class)->deleteBackup($folder))->toBeTrue()
        ->and(File::isDirectory($folder->path))->toBeFalse();
});

it('restores a backup folder onto the project root', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    Process::fake();
    $folder = resolve(Sync::class)->backups()->sole();

    expect(resolve(Sync::class)->restoreBackup($folder, dry: false))->toBeTrue();

    Process::assertRan(fn ($process) => in_array("{$folder->path}/", $process->command, true)
        && in_array(base_path().'/', $process->command, true)
        && ! in_array('--dry-run', $process->command, true));
});

it('restores a backup folder as a dry run, adding --dry-run', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    Process::fake();
    $folder = resolve(Sync::class)->backups()->sole();

    expect(resolve(Sync::class)->restoreBackup($folder, dry: true))->toBeTrue();

    Process::assertRan(fn ($process) => in_array('--dry-run', $process->command, true));
});

it('reports failure when the restore process fails', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    Process::fake(fn () => Process::result(exitCode: 1));
    $folder = resolve(Sync::class)->backups()->sole();

    expect(resolve(Sync::class)->restoreBackup($folder, dry: false))->toBeFalse();
});

it('refuses to restore a backup folder that has been replaced by a symlink since it was listed', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    Process::fake();
    $folder = resolve(Sync::class)->backups()->sole();

    File::deleteDirectory($folder->path);

    // Not `File::link()`: creating a symlink needs a privilege CI's Windows runners
    // don't grant by default, so this skips (rather than failing for an unrelated
    // reason) wherever the environment can't actually create one.
    if (! @symlink(base_path(), $folder->path)) {
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    try {
        expect(resolve(Sync::class)->restoreBackup($folder, dry: false))->toBeFalse();

        Process::assertNothingRan();
    } finally {
        @unlink($folder->path);
    }
});

it('refuses to delete a backup folder that has been replaced by a symlink since it was listed', function () {
    // Simulates the race sync:backups-clean's interactive prompts leave open: the
    // folder was a real directory when backups() listed it, but is a symlink by the
    // time deleteBackup() actually runs.
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    $folder = resolve(Sync::class)->backups()->sole();

    $target = sys_get_temp_dir().'/sync-outside-'.Str::random(8);
    File::ensureDirectoryExists($target);
    File::put("{$target}/important.txt", 'do not delete me');

    File::deleteDirectory($folder->path);

    if (! @symlink($target, $folder->path)) {
        File::deleteDirectory($target);
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    try {
        expect(resolve(Sync::class)->deleteBackup($folder))->toBeFalse()
            ->and(File::exists("{$target}/important.txt"))->toBeTrue();
    } finally {
        @unlink($folder->path);
        File::deleteDirectory($target);
    }
});

it('refuses to act on a backup folder whose parent backup_dir symlink was repointed outside the project since it was listed', function () {
    // guardBackupDirSafe() explicitly allows backup_dir to be a symlink, as long as it
    // resolves inside the project when validated (at listing time, inside backups()).
    // If that symlink is later repointed outside the project, the *leaf* backup folder
    // it now resolves through can still be a perfectly real directory — is_link() on
    // the leaf alone can't catch this, only a real-path containment check against the
    // project root can.
    $inside = "{$this->backupPath}-inside";
    File::ensureDirectoryExists("{$inside}/2026-07-24_134530");

    $linkDir = 'sync-backups-symlink-'.Str::random(8);
    $linkPath = base_path($linkDir);

    if (! @symlink($inside, $linkPath)) {
        File::deleteDirectory($inside);
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    config(['sync.backup_dir' => $linkDir]);
    Process::fake();

    $folder = resolve(Sync::class)->backups()->sole();

    $outside = sys_get_temp_dir().'/sync-outside-'.Str::random(8);
    File::ensureDirectoryExists("{$outside}/2026-07-24_134530");

    // Repoint the backup_dir symlink itself — the ancestor, not the listed folder's
    // own leaf, which stays a real (non-symlink) directory throughout. Both removal
    // calls are attempted, suppressed: a directory-target symlink needs unlink() on
    // POSIX but rmdir() on Windows (where it's materialized as a directory junction),
    // and using the wrong one silently no-ops instead of failing loudly.
    @unlink($linkPath);
    @rmdir($linkPath);
    @symlink($outside, $linkPath);

    try {
        expect(resolve(Sync::class)->restoreBackup($folder, dry: false))->toBeFalse()
            ->and(resolve(Sync::class)->deleteBackup($folder))->toBeFalse();

        Process::assertNothingRan();
    } finally {
        @unlink($linkPath);
        @rmdir($linkPath);
        File::deleteDirectory($inside);
        File::deleteDirectory($outside);
    }
});

it('refuses to act on a backup folder whose parent backup_dir symlink was repointed to a different location still inside the project', function () {
    // A root-containment check alone would pass here: the repointed target is a real,
    // same-named directory, and it's still inside the project. Only comparing against
    // the canonical path captured at listing time (BackupFolder::$canonicalPath) can
    // tell the two apart.
    $inside = "{$this->backupPath}-inside";
    File::ensureDirectoryExists("{$inside}/2026-07-24_134530");

    $linkDir = 'sync-backups-symlink-'.Str::random(8);
    $linkPath = base_path($linkDir);

    if (! @symlink($inside, $linkPath)) {
        File::deleteDirectory($inside);
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    config(['sync.backup_dir' => $linkDir]);
    Process::fake();

    $folder = resolve(Sync::class)->backups()->sole();

    $elsewhereInside = base_path('sync-backups-elsewhere-'.Str::random(8));
    File::ensureDirectoryExists("{$elsewhereInside}/2026-07-24_134530");

    @unlink($linkPath);
    @rmdir($linkPath);
    @symlink($elsewhereInside, $linkPath);

    try {
        expect(resolve(Sync::class)->restoreBackup($folder, dry: false))->toBeFalse()
            ->and(resolve(Sync::class)->deleteBackup($folder))->toBeFalse();

        Process::assertNothingRan();
    } finally {
        @unlink($linkPath);
        @rmdir($linkPath);
        File::deleteDirectory($inside);
        File::deleteDirectory($elsewhereInside);
    }
});

it('reports failure when a backup folder survives its own delete attempt', function () {
    File::ensureDirectoryExists("{$this->backupPath}/2026-07-24_134530");
    $folder = resolve(Sync::class)->backups()->sole();

    File::partialMock()
        ->shouldReceive('deleteDirectory')
        ->once()
        ->with($folder->path)
        ->andReturn(false);

    expect(resolve(Sync::class)->deleteBackup($folder))->toBeFalse();
});

it('allows a normal backup directory', function () {
    resolve(Sync::class)->guardBackupDirSafe($this->backupDir);
})->throwsNoExceptions();

it('returns the dot-collapsed directory, not the raw configured string', function () {
    expect(resolve(Sync::class)->guardBackupDirSafe("storage/../{$this->backupDir}"))
        ->toBe($this->backupDir);
});

it('refuses a blank backup directory', function () {
    resolve(Sync::class)->guardBackupDirSafe('');
})->throws(
    SyncException::class,
    'The backup directory "" resolves outside your project, or to the project root itself. Set a backup_dir inside your project.',
);

it('refuses a backup directory that resolves to the project root itself', function () {
    resolve(Sync::class)->guardBackupDirSafe('.');
})->throws(SyncException::class);

it('refuses a backup directory that steps above the project root', function () {
    resolve(Sync::class)->guardBackupDirSafe('../outside');
})->throws(SyncException::class);

it('refuses a backup directory that steps above the root before coming back down', function () {
    resolve(Sync::class)->guardBackupDirSafe('storage/../../outside');
})->throws(SyncException::class);

it('refuses an absolute unix-style backup directory', function () {
    resolve(Sync::class)->guardBackupDirSafe('/tmp');
})->throws(SyncException::class);

it('refuses an absolute windows-style backup directory', function () {
    resolve(Sync::class)->guardBackupDirSafe('C:\\Windows\\Temp');
})->throws(SyncException::class);

it('refuses a backup directory that is a symlink pointing straight at the project root', function () {
    $linkDir = 'sync-backups-symlink-'.Str::random(8);
    $linkPath = base_path($linkDir);

    if (! @symlink(base_path(), $linkPath)) {
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    try {
        expect(fn () => resolve(Sync::class)->guardBackupDirSafe($linkDir))->toThrow(SyncException::class);
    } finally {
        @unlink($linkPath);
    }
});

it('refuses a backup directory that is a symlink escaping the project', function () {
    $target = sys_get_temp_dir().'/sync-outside-'.Str::random(8);
    File::ensureDirectoryExists($target);

    $linkDir = 'sync-backups-symlink-'.Str::random(8);
    $linkPath = base_path($linkDir);

    if (! @symlink($target, $linkPath)) {
        File::deleteDirectory($target);
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    try {
        expect(fn () => resolve(Sync::class)->guardBackupDirSafe($linkDir))->toThrow(SyncException::class);
    } finally {
        @unlink($linkPath);
        File::deleteDirectory($target);
    }
});

it('allows a backup directory that is a symlink staying inside the project', function () {
    File::ensureDirectoryExists("{$this->backupPath}-real");

    $linkDir = 'sync-backups-symlink-'.Str::random(8);
    $linkPath = base_path($linkDir);

    if (! @symlink("{$this->backupPath}-real", $linkPath)) {
        File::deleteDirectory("{$this->backupPath}-real");
        $this->markTestSkipped('This environment does not support creating symlinks.');
    }

    try {
        resolve(Sync::class)->guardBackupDirSafe($linkDir);
    } finally {
        @unlink($linkPath);
        File::deleteDirectory("{$this->backupPath}-real");
    }
})->throwsNoExceptions();

it('refuses to prepare a pull with an unsafe backup directory', function () {
    config(['sync.backup_dir' => '../outside']);

    $sync = resolve(Sync::class);
    $backup = new Backup('../outside', '2026-07-24_134530');

    $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );
})->throws(SyncException::class);

it('validates the given Backup\'s own dir, not just the currently configured backup_dir', function () {
    // sync.backup_dir (from beforeEach) is a safe, unique dir — but the Backup instance
    // being prepared carries its own (unsafe) dir, which must be what gets checked.
    $sync = resolve(Sync::class);
    $backup = new Backup('../outside', '2026-07-24_134530');

    $sync->prepare(
        Operation::Pull,
        $sync->remote('staging'),
        collect([$sync->recipe('assets')]),
        new RsyncOptions([]),
        $backup,
    );
})->throws(SyncException::class);

it('gets the same lock for the same remote', function () {
    // Unique root, not "staging": lock files are keyed by root (not config name) and live
    // under the real storage_path(), shared across parallel test workers.
    config(['sync.remotes' => array_merge(config('sync.remotes'), [
        $name = 'lock-test-'.Str::random(8) => ['root' => base_path('storage/app/'.$name)],
    ])]);

    $sync = resolve(Sync::class);
    $remote = $sync->remote($name);

    $first = $sync->lock($remote);
    expect($first->acquire())->toBeTrue();

    $second = $sync->lock($remote);
    expect($second->acquire())->toBeFalse();

    $first->release();

    File::delete($first->path);
});

it('gets independent locks for different remotes', function () {
    // Roots must differ, not just names — the lock key ignores the config name.
    config(['sync.remotes' => array_merge(config('sync.remotes'), [
        $nameA = 'lock-test-a-'.Str::random(8) => ['root' => base_path('storage/app/'.$nameA)],
        $nameB = 'lock-test-b-'.Str::random(8) => ['root' => base_path('storage/app/'.$nameB)],
    ])]);

    $sync = resolve(Sync::class);

    $lockA = $sync->lock($sync->remote($nameA));
    $lockB = $sync->lock($sync->remote($nameB));

    expect($lockA->acquire())->toBeTrue();
    expect($lockB->acquire())->toBeTrue();

    $lockA->release();
    $lockB->release();

    File::delete($lockA->path);
    File::delete($lockB->path);
});

it('gets the same lock for two remotes whose root differs only by a duplicate slash', function () {
    // Remote::path() collapses duplicate slashes, so these two roots reach the same rsync
    // target — the lock key must collapse them too, or the aliases bypass the guard.
    $root = base_path('storage/app/lock-test-'.Str::random(8));

    config(['sync.remotes' => array_merge(config('sync.remotes'), [
        'alias-plain' => ['root' => "{$root}/nested"],
        'alias-doubled' => ['root' => "{$root}//nested"],
    ])]);

    $sync = resolve(Sync::class);

    $plain = $sync->lock($sync->remote('alias-plain'));
    $doubled = $sync->lock($sync->remote('alias-doubled'));

    expect($doubled->path)->toBe($plain->path);

    expect($plain->acquire())->toBeTrue();
    expect($doubled->acquire())->toBeFalse();

    $plain->release();

    File::delete($plain->path);
});

it('gets the same lock for two remotes whose root differs only by a redundant dot segment', function () {
    // These two roots resolve to the same directory on disk even though Remote::path()
    // never collapses ".." itself — the lock key must still see them as one target.
    $root = base_path('storage/app/lock-test-'.Str::random(8));

    config(['sync.remotes' => array_merge(config('sync.remotes'), [
        'alias-plain' => ['root' => "{$root}/nested"],
        'alias-dotted' => ['root' => "{$root}/tmp/../nested"],
    ])]);

    $sync = resolve(Sync::class);

    $plain = $sync->lock($sync->remote('alias-plain'));
    $dotted = $sync->lock($sync->remote('alias-dotted'));

    expect($dotted->path)->toBe($plain->path);

    expect($plain->acquire())->toBeTrue();
    expect($dotted->acquire())->toBeFalse();

    $plain->release();

    File::delete($plain->path);
});
