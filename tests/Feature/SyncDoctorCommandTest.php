<?php

declare(strict_types=1);

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function () {
    Process::fake();

    config([
        'sync.remotes' => [
            'production' => ['user' => 'forge', 'host' => '1.2.3.4', 'root' => '/srv/app', 'read_only' => true],
            'staging' => ['user' => 'forge', 'host' => '5.6.7.8', 'root' => '/srv/staging'],
            'local' => ['root' => '/srv/local'],
        ],
    ]);
});

it('reports success when the local rsync, SSH connection, and remote rsync all check out', function () {
    // Only one substring assertion targets the table itself: Laravel Prompts' table()
    // renders the whole table as a single write, and the testing mock's doWrite()
    // matcher only lets the first registered expectation claim a given call — a second
    // expectsOutputToContain() aimed at the same write would never get the chance to
    // match. Process::assertRan() below verifies each row's underlying check directly.
    $this->artisan('sync:doctor', ['remote' => 'production'])
        ->expectsOutputToContain('SSH connection')
        ->expectsOutputToContain('Everything looks good — ready to sync.')
        ->assertSuccessful();

    Process::assertRan(fn ($process) => in_array('rsync', $process->command, true) && in_array('--version', $process->command, true));
    Process::assertRan(fn ($process) => in_array("test -d '/srv/app'", $process->command, true));
    Process::assertRan(fn ($process) => in_array('command -v rsync', $process->command, true));
});

it('fails and reports a friendly error when local rsync is missing', function () {
    Process::fake(fn ($process) => in_array('--version', $process->command, true)
        ? Process::result(exitCode: 127)
        : Process::result());

    $this->artisan('sync:doctor', ['remote' => 'production'])
        ->expectsOutputToContain('FAILED (not found on PATH)')
        ->expectsOutputToContain('One or more checks failed')
        ->assertFailed();
});

it('skips the SSH checks for a local remote, without spawning a process for it', function () {
    $this->artisan('sync:doctor', ['remote' => 'local'])
        ->expectsOutputToContain('skipped, local remote')
        ->expectsOutputToContain('Everything looks good')
        ->assertSuccessful();

    Process::assertRanTimes(fn ($process) => true, 1);
});

it('fails when the SSH connection or root check fails, skipping the now-unreachable remote rsync check', function () {
    Process::fake(fn ($process) => in_array("test -d '/srv/app'", $process->command, true)
        ? Process::result(exitCode: 255)
        : Process::result());

    $this->artisan('sync:doctor', ['remote' => 'production'])
        ->expectsOutputToContain('FAILED (see sync:test-connection)')
        ->assertFailed();

    // Local rsync (1) + SSH connection (1) only — the remote rsync check never runs,
    // since it would need the very same already-failed SSH connection.
    Process::assertRanTimes(fn ($process) => true, 2);
    Process::assertNotRan(fn ($process) => in_array('command -v rsync', $process->command, true));
});

it('fails when rsync is missing on the remote', function () {
    Process::fake(fn ($process) => in_array('command -v rsync', $process->command, true)
        ? Process::result(exitCode: 1)
        : Process::result());

    $this->artisan('sync:doctor', ['remote' => 'production'])
        ->expectsOutputToContain('FAILED (not found on remote)')
        ->assertFailed();
});

it('reports a distinct reason when the remote rsync check fails with ssh\'s own connection-failure exit code, instead of misdiagnosing it as a missing binary', function () {
    // ssh (not the remote command it ran) exits 255 specifically when the connection
    // itself drops or fails mid-session — distinct from "command -v rsync exited
    // non-zero because rsync isn't installed". A second, unrelated connection failure
    // right after the connection check already succeeded shouldn't be reported the
    // same way as a genuinely missing binary.
    Process::fake(fn ($process) => in_array('command -v rsync', $process->command, true)
        ? Process::result(exitCode: 255)
        : Process::result());

    $this->artisan('sync:doctor', ['remote' => 'production'])
        ->expectsOutputToContain('FAILED (SSH connection failed unexpectedly)')
        ->assertFailed();
});

it('fails, rather than hanging, when the local rsync check times out', function () {
    Process::fake(function ($process) {
        if (in_array('--version', $process->command, true)) {
            throw new ProcessTimedOutException(
                new SymfonyProcessTimedOutException(new SymfonyProcess(['rsync']), SymfonyProcessTimedOutException::TYPE_GENERAL),
                Process::result(exitCode: -1),
            );
        }

        return Process::result();
    });

    $this->artisan('sync:doctor', ['remote' => 'production'])
        ->expectsOutputToContain('FAILED (timed out after 10 seconds)')
        ->assertFailed();
});

it('reports a distinct timeout failure for the SSH connection check', function () {
    Process::fake(function ($process) {
        if (in_array("test -d '/srv/app'", $process->command, true)) {
            throw new ProcessTimedOutException(
                new SymfonyProcessTimedOutException(new SymfonyProcess(['ssh']), SymfonyProcessTimedOutException::TYPE_GENERAL),
                Process::result(exitCode: -1),
            );
        }

        return Process::result();
    });

    $this->artisan('sync:doctor', ['remote' => 'production'])
        ->expectsOutputToContain('FAILED (timed out after 10 seconds)')
        ->assertFailed();

    // The remote rsync check never runs once the connection itself timed out.
    Process::assertNotRan(fn ($process) => in_array('command -v rsync', $process->command, true));
});

it('checks every configured remote with --all', function () {
    // Only one substring assertion targets the table (see the note in the previous
    // test for why several can't share a single table render). Process assertions
    // below confirm every remote — production, staging, and the local one — was
    // actually visited.
    $this->artisan('sync:doctor', ['--all' => true])
        ->expectsOutputToContain('staging')
        ->expectsOutputToContain('Everything looks good')
        ->assertSuccessful();

    // Local rsync (1) + 2 SSH checks per non-local remote (production, staging) = 5.
    Process::assertRanTimes(fn ($process) => true, 5);
    Process::assertRan(fn ($process) => in_array('forge@1.2.3.4', $process->command, true));
    Process::assertRan(fn ($process) => in_array('forge@5.6.7.8', $process->command, true));
});

it('prompts for the remote when omitted interactively', function () {
    $this->artisan('sync:doctor')
        ->expectsChoice('Which remote do you want to check?', 'production', ['production', 'staging', 'local'])
        ->expectsOutputToContain('Everything looks good')
        ->assertSuccessful();
});

it('fails with a friendly error when no remote is given non-interactively and --all is not passed', function () {
    $this->artisan('sync:doctor', ['--no-interaction' => true])
        ->expectsOutputToContain('You must specify a remote.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails with a friendly error for an unknown remote', function () {
    $this->artisan('sync:doctor', ['remote' => 'missing'])
        ->expectsOutputToContain('The remote "missing" is not defined in your config/sync.php file.')
        ->assertFailed();

    Process::assertNothingRan();
});

it('fails with a friendly error when no remotes are configured at all', function () {
    config(['sync.remotes' => []]);

    $this->artisan('sync:doctor', ['--all' => true])
        ->expectsOutputToContain('You need to define at least one remote in your config/sync.php file.')
        ->assertFailed();

    Process::assertNothingRan();
});
