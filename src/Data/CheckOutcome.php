<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Data;

/**
 * One row of `sync:doctor`'s report, computed once per check — the table and the
 * overall pass/fail in `SyncDoctorCommand::handle()` each derive from the full list of
 * these independently, instead of a row/healthy pair threaded through the same loop.
 */
final readonly class CheckOutcome
{
    public function __construct(
        public string $remote,
        public string $check,
        public string $result,
        public bool $passed,
    ) {}

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public function toRow(): array
    {
        return [$this->remote, $this->check, $this->result];
    }
}
