<?php

declare(strict_types=1);

use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Ssh\ConnectionCommand;

beforeEach(function () {
    $this->remote = Remote::fromArray('production', [
        'user' => 'forge',
        'host' => '104.26.3.113',
        'port' => 2222,
        'root' => '/home/forge/example.com',
    ]);
});

it('builds an argument list without shell interpretation of paths or options', function () {
    $command = new ConnectionCommand($this->remote);

    expect($command->toArgs())->toBe([
        'ssh',
        '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-o', 'StrictHostKeyChecking=accept-new',
        '-p', '2222',
        'forge@104.26.3.113',
        "test -d '/home/forge/example.com'",
    ]);
});

it('renders the command as a space separated string', function () {
    $command = new ConnectionCommand($this->remote);

    expect((string) $command)->toBe(
        "ssh -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new -p 2222 forge@104.26.3.113 test -d '/home/forge/example.com'",
    );
});

it('escapes a single quote in the root for the remote shell', function () {
    $remote = Remote::fromArray('production', [
        'user' => 'forge',
        'host' => '104.26.3.113',
        'root' => "/srv/app's data",
    ]);

    expect((new ConnectionCommand($remote))->toArgs())->toContain("test -d '/srv/app'\\''s data'");
});

it('checks the filesystem root when the configured root normalizes to an empty string', function () {
    $remote = Remote::fromArray('production', [
        'user' => 'forge',
        'host' => '104.26.3.113',
        'root' => '/',
    ]);

    expect((new ConnectionCommand($remote))->toArgs())->toContain("test -d '/'");
});
