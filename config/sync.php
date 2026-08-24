<?php

declare(strict_types=1);

return [

    /*
    | Remotes
    | Define one or more remotes you want to sync with.
    | Each remote is an array with 'user', 'host', 'root', and optionally 'port' and 'read_only'.
    | Omit 'user' and 'host' to treat the remote as a local path (no ssh involved).
    */
    'remotes' => [

        // 'production' => [
        //     'user' => 'forge',
        //     'host' => '104.26.3.113',
        //     'port' => 22,
        //     'root' => '/home/forge/example.com',
        //     'read_only' => env('SYNC_PRODUCTION_READ_ONLY', true),
        // ],

    ],

    /*
    | Recipes
    | Define one or more recipes with the paths you want to sync.
    | Each recipe is an array of relative paths to your project's root.
    */
    'recipes' => [

        // 'assets' => ['storage/app/assets/', 'storage/app/img/'],

    ],

    /*
    | Options
    | An array of default rsync options.
    | You can override these options when executing the command.
    */
    'options' => [
        '--archive',
    ],

    /*
    | Excludes
    | Optional, keyed by recipe name. An array of rsync --exclude patterns applied only
    | when that recipe is synced, on top of the options above.
    */
    'excludes' => [

        // 'assets' => ['*.log', 'node_modules/'],

    ],

    /*
    | Excludes From
    | Optional, keyed by recipe name. An array of file paths (relative to your project's
    | root), each containing rsync exclude patterns (one per line), applied via rsync's
    | own --exclude-from when that recipe is synced — useful for a long exclude list
    | you'd rather keep in its own file than inline in this config. A relative path is
    | resolved from your project's root; an absolute one is used as written.
    */
    'excludes_from' => [

        // 'assets' => ['.rsync-excludes'],

    ],

    /*
    | Backup Directory
    | Relative to your project's root. Before a real pull, when backups are enabled
    | (--backup / -B), the local files of the selected recipes are copied into a
    | timestamped folder under this directory. Run `php artisan sync:backups-clean`
    | to delete old backup folders.
    */
    'backup_dir' => '.sync-backups',

];
