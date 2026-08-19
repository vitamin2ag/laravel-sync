<?php

declare(strict_types=1);

namespace Vitamin2\Sync;

use Illuminate\Support\ServiceProvider;
use Override;
use Vitamin2\Sync\Commands\SyncBackupsCleanCommand;
use Vitamin2\Sync\Commands\SyncBackupsRestoreCommand;
use Vitamin2\Sync\Commands\SyncCommand;
use Vitamin2\Sync\Commands\SyncCommandsCommand;
use Vitamin2\Sync\Commands\SyncListCommand;
use Vitamin2\Sync\Commands\SyncTestConnectionCommand;

class SyncServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sync.php', 'sync');

        $this->app->singleton(Sync::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/sync.php' => config_path('sync.php'),
        ], ['laravel-sync', 'laravel-sync-config']);

        $this->commands([
            SyncCommand::class,
            SyncListCommand::class,
            SyncCommandsCommand::class,
            SyncBackupsCleanCommand::class,
            SyncBackupsRestoreCommand::class,
            SyncTestConnectionCommand::class,
        ]);
    }
}
