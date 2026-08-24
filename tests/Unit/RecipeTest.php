<?php

declare(strict_types=1);

use Vitamin2\Sync\Data\Recipe;

it('hydrates a name and its paths', function () {
    $recipe = Recipe::fromArray('assets', ['storage/app/assets/', 'storage/app/img/']);

    expect($recipe->name)->toBe('assets')
        ->and($recipe->paths)->toBe(['storage/app/assets/', 'storage/app/img/'])
        ->and($recipe->excludes)->toBe([])
        ->and($recipe->excludesFrom)->toBe([]);
});

it('hydrates the given excludes', function () {
    $recipe = Recipe::fromArray('assets', ['storage/app/assets/'], ['*.log', 'node_modules/']);

    expect($recipe->excludes)->toBe(['*.log', 'node_modules/']);
});

it('hydrates the given excludes-from files', function () {
    $recipe = Recipe::fromArray('assets', ['storage/app/assets/'], [], ['.rsync-excludes']);

    expect($recipe->excludesFrom)->toBe(['.rsync-excludes']);
});

it('normalizes Windows-style backslash separators in excludes-from files', function () {
    // The guard and the `--exclude-from=` flag both read this value, so it is normalized once
    // here rather than by each of them.
    $recipe = Recipe::fromArray('assets', ['storage/app/assets/'], [], ['storage\\app\\.rsync-excludes']);

    expect($recipe->excludesFrom)->toBe(['storage/app/.rsync-excludes']);
});

it('normalizes excludes-from files built through the constructor, not just fromArray()', function () {
    // Sync::prepare() accepts any caller-built Recipe, and its guard reads these values as
    // already normalized — so the constructor, not the factory, has to hold that invariant.
    $recipe = new Recipe('assets', ['storage/app/assets/'], [], [' ..\\..\\etc\\rsync-rules ']);

    expect($recipe->excludesFrom)->toBe(['../../etc/rsync-rules']);
});
