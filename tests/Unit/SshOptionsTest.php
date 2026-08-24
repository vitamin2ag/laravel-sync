<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Vitamin2\Sync\Ssh\SshOptions;

it('returns null when a started process times out while waiting', function () {
    // Process::fake() only ever throws a faked timeout eagerly from start(), never
    // from a FakeInvokedProcess's own wait() — so this needs a real InvokedProcess
    // whose wait() throws, not a fake one, to exercise wait()'s own catch.
    $process = new class implements InvokedProcess
    {
        public function id() {}

        public function command() {}

        public function signal(int $signal) {}

        public function running() {}

        public function output() {}

        public function errorOutput() {}

        public function latestOutput() {}

        public function latestErrorOutput() {}

        public function wait(?callable $output = null)
        {
            throw new ProcessTimedOutException(
                new SymfonyProcessTimedOutException(new SymfonyProcess(['sleep', '30']), SymfonyProcessTimedOutException::TYPE_GENERAL),
                Process::result(exitCode: -1),
            );
        }

        public function waitUntil(?callable $output = null) {}
    };

    expect(SshOptions::wait($process))->toBeNull();
});
