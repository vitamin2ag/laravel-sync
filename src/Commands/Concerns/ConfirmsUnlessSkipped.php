<?php

declare(strict_types=1);

namespace Vitamin2\Sync\Commands\Concerns;

use Closure;
use Illuminate\Console\Command;

/**
 * Shared "confirm before a destructive action, unless skipped" gate for the sync
 * commands that actually run something (`sync`, `sync:backups-clean`, `sync:backups-restore`).
 *
 * @mixin Command
 */
trait ConfirmsUnlessSkipped
{
    /**
     * `$confirm` is a closure so it runs only when a prompt is actually needed —
     * `Laravel\Prompts\confirm()` prints the question as a side effect.
     *
     * @param  Closure(): bool  $confirm
     */
    protected function confirmUnlessSkipped(bool $skip, Closure $confirm): bool
    {
        if ($skip) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return true;
        }

        return $confirm();
    }
}
