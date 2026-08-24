<?php

declare(strict_types=1);

use Vitamin2\Sync\Rsync\RsyncOptions;

it('keeps the given flags, deduplicated', function () {
    $options = RsyncOptions::resolve(['--archive', '--compress', '--archive'], dry: false, verbose: false);

    expect($options->flags)->toBe(['--archive', '--compress']);
});

it('adds the dry-run flags on a dry run', function () {
    $options = RsyncOptions::resolve(['--archive'], dry: true, verbose: false);

    expect($options->flags)->toContain('--archive', '--dry-run', '--human-readable', '--progress', '--stats', '--verbose');
});

it('adds the output flags on a verbose run', function () {
    $options = RsyncOptions::resolve(['--archive'], dry: false, verbose: true);

    expect($options->flags)->toContain('--archive', '--human-readable', '--progress', '--stats', '--verbose')
        ->and($options->flags)->not->toContain('--dry-run');
});

it('does not duplicate flags already present when merging dry-run additions', function () {
    $options = RsyncOptions::resolve(['--archive', '--verbose'], dry: true, verbose: false);

    expect(array_count_values($options->flags)['--verbose'])->toBe(1);
});

it('renders the flags as a space separated string', function () {
    $options = new RsyncOptions(['--archive', '--compress']);

    expect((string) $options)->toBe('--archive --compress');
});

it('reports whether any flag produces visible output', function () {
    expect((new RsyncOptions(['--archive']))->producesOutput())->toBeFalse()
        ->and((new RsyncOptions(['--archive', '--progress']))->producesOutput())->toBeTrue();
});

it('treats a flag outside the curated list as producing output', function () {
    expect((new RsyncOptions(['--archive', '--info=progress2']))->producesOutput())->toBeTrue();
});

it('does not treat an --exclude flag as producing output', function () {
    $options = (new RsyncOptions(['--archive']))->withExcludes(['*.log']);

    expect($options->producesOutput())->toBeFalse();
});

it('keeps a literal "0" flag instead of treating it as empty', function () {
    $options = RsyncOptions::resolve(['--archive', '0'], dry: false, verbose: false);

    expect($options->flags)->toBe(['--archive', '0']);
});

it('strips rsync\'s own backup flags when backup is true', function () {
    $options = RsyncOptions::resolve(
        ['--archive', '--backup', '--backup-dir=/tmp/old'],
        dry: false,
        verbose: false,
        backup: true,
    );

    expect($options->flags)->toBe(['--archive']);
});

it('keeps rsync\'s own backup flags when backup is false', function () {
    $options = RsyncOptions::resolve(['--archive', '--backup'], dry: false, verbose: false, backup: false);

    expect($options->flags)->toBe(['--archive', '--backup']);
});

it('strips rsync\'s short -b backup flag when backup is true', function () {
    $options = RsyncOptions::resolve(['--archive', '-b'], dry: false, verbose: false, backup: true);

    expect($options->flags)->toBe(['--archive']);
});

it('strips only the "b" from a short-option cluster, keeping its other flags', function () {
    $options = RsyncOptions::resolve(['-avhb'], dry: false, verbose: false, backup: true);

    expect($options->flags)->toBe(['-avh']);
});

it('keeps the short -b flag when backup is false', function () {
    $options = RsyncOptions::resolve(['-b'], dry: false, verbose: false, backup: false);

    expect($options->flags)->toBe(['-b']);
});

it('strips the two-token --backup-dir form, including its value, when backup is true', function () {
    $options = RsyncOptions::resolve(
        ['--archive', '--backup-dir', '/tmp/old'],
        dry: false,
        verbose: false,
        backup: true,
    );

    expect($options->flags)->toBe(['--archive']);
});

it('keeps the two-token --backup-dir form when backup is false', function () {
    $options = RsyncOptions::resolve(['--backup-dir', '/tmp/old'], dry: false, verbose: false, backup: false);

    expect($options->flags)->toBe(['--backup-dir', '/tmp/old']);
});

it('drops a trailing --backup-dir with no value instead of erroring', function () {
    $options = RsyncOptions::resolve(['--archive', '--backup-dir'], dry: false, verbose: false, backup: true);

    expect($options->flags)->toBe(['--archive']);
});

it('appends an --exclude flag per pattern', function () {
    $options = (new RsyncOptions(['--archive']))->withExcludes(['*.log', 'node_modules/']);

    expect($options->flags)->toBe(['--archive', '--exclude=*.log', '--exclude=node_modules/']);
});

it('returns the same instance when there are no excludes', function () {
    $options = new RsyncOptions(['--archive']);

    expect($options->withExcludes([]))->toBe($options);
});

it('appends an --exclude-from flag per file, verbatim', function () {
    // Resolution is Sync::resolveExcludesFromPath()'s job, so the guard and this flag can't
    // disagree about which file is meant; these options just format what they are given.
    $options = (new RsyncOptions(['--archive']))->withExcludeFrom(['/srv/app/.rsync-excludes', '/mnt/shared/other.txt']);

    expect($options->flags)->toBe([
        '--archive',
        '--exclude-from=/srv/app/.rsync-excludes',
        '--exclude-from=/mnt/shared/other.txt',
    ]);
});

it('returns the same instance when there are no excludes-from files', function () {
    $options = new RsyncOptions(['--archive']);

    expect($options->withExcludeFrom([]))->toBe($options);
});

it('does not treat an --exclude-from flag as producing output', function () {
    $options = (new RsyncOptions(['--archive']))->withExcludeFrom(['.rsync-excludes']);

    expect($options->producesOutput())->toBeFalse();
});
