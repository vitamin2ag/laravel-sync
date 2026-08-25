<?php

declare(strict_types=1);

use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Enums\Operation;
use Vitamin2\Sync\Rsync\RsyncCommand;
use Vitamin2\Sync\Rsync\RsyncOptions;

beforeEach(function () {
    $this->remote = Remote::fromArray('production', [
        'user' => 'forge',
        'host' => '104.26.3.113',
        'root' => '/home/forge/example.com',
    ]);
});

it('builds a push command from the local path to the remote path', function () {
    $command = new RsyncCommand(Operation::Push, $this->remote, 'storage/app/assets/', new RsyncOptions(['--archive']));

    expect($command->origin())->toBe(base_path('storage/app/assets/'))
        ->and($command->target())->toBe('forge@104.26.3.113:/home/forge/example.com/storage/app/assets/')
        ->and((string) $command)->toBe(sprintf(
            "rsync -e 'ssh -p 22 -o StrictHostKeyChecking=accept-new' --archive %s forge@104.26.3.113:/home/forge/example.com/storage/app/assets/",
            base_path('storage/app/assets/'),
        ));
});

it('builds a pull command from the remote path to the local path', function () {
    $command = new RsyncCommand(Operation::Pull, $this->remote, 'storage/app/assets/', new RsyncOptions(['--archive']));

    expect($command->origin())->toBe('forge@104.26.3.113:/home/forge/example.com/storage/app/assets/')
        ->and($command->target())->toBe(base_path('storage/app/assets/'))
        ->and((string) $command)->toBe(sprintf(
            "rsync -e 'ssh -p 22 -o StrictHostKeyChecking=accept-new' --archive forge@104.26.3.113:/home/forge/example.com/storage/app/assets/ %s",
            base_path('storage/app/assets/'),
        ));
});

it('uses the configured port', function () {
    $remote = Remote::fromArray('production', ['user' => 'forge', 'host' => '1.2.3.4', 'port' => 1431, 'root' => '/srv/app']);
    $command = new RsyncCommand(Operation::Push, $remote, 'assets/', new RsyncOptions(['--archive']));

    expect((string) $command)->toContain('ssh -p 1431');
});

it('omits the ssh flag and the user@host prefix for a local remote', function () {
    $remote = Remote::fromArray('local', ['root' => '/var/www/example.com']);
    $command = new RsyncCommand(Operation::Push, $remote, 'assets/', new RsyncOptions(['--archive']));

    expect((string) $command)->toBe(sprintf(
        'rsync --archive %s /var/www/example.com/assets/',
        base_path('assets/'),
    ))->and($command->target())->toBe('/var/www/example.com/assets/');
});

it('converts to an array with origin, target, options, and port', function () {
    $command = new RsyncCommand(Operation::Push, $this->remote, 'assets/', new RsyncOptions(['--archive']));

    expect($command->toArray())->toBe([
        'origin' => base_path('assets/'),
        'target' => 'forge@104.26.3.113:/home/forge/example.com/assets/',
        'options' => '--archive',
        'port' => '22',
    ]);
});

it('uses a dash for the port of a local remote in the array form', function () {
    $remote = Remote::fromArray('local', ['root' => '/var/www/example.com']);
    $command = new RsyncCommand(Operation::Push, $remote, 'assets/', new RsyncOptions(['--archive']));

    expect($command->toArray()['port'])->toBe('-');
});

it('builds an argument list without shell interpretation of paths or options', function () {
    $command = new RsyncCommand(Operation::Push, $this->remote, 'storage/app/assets/', new RsyncOptions(['--archive']));

    expect($command->toArgs())->toBe([
        'rsync',
        '-e',
        'ssh -p 22 -o StrictHostKeyChecking=accept-new',
        '--archive',
        base_path('storage/app/assets/'),
        'forge@104.26.3.113:/home/forge/example.com/storage/app/assets/',
    ]);
});

it('omits the ssh args from the argument list for a local remote', function () {
    $remote = Remote::fromArray('local', ['root' => '/var/www/example.com']);
    $command = new RsyncCommand(Operation::Push, $remote, 'assets/', new RsyncOptions(['--archive']));

    expect($command->toArgs())->toBe([
        'rsync',
        '--archive',
        base_path('assets/'),
        '/var/www/example.com/assets/',
    ]);
});
