<?php

declare(strict_types=1);

use Vitamin2\Sync\Data\Remote;
use Vitamin2\Sync\Ssh\RsyncAvailableCommand;

beforeEach(function () {
    $this->remote = Remote::fromArray('production', [
        'user' => 'forge',
        'host' => '104.26.3.113',
        'port' => 2222,
        'root' => '/home/forge/example.com',
    ]);
});

it('builds an argument list without shell interpretation', function () {
    $command = new RsyncAvailableCommand($this->remote);

    expect($command->toArgs())->toBe([
        'ssh',
        '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5',
        '-p', '2222',
        'forge@104.26.3.113',
        'command -v rsync',
    ]);
});

it('renders the command as a space separated string', function () {
    $command = new RsyncAvailableCommand($this->remote);

    expect((string) $command)->toBe(
        'ssh -o BatchMode=yes -o ConnectTimeout=5 -p 2222 forge@104.26.3.113 command -v rsync',
    );
});
