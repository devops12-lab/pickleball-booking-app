<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Backup & Restore
    |--------------------------------------------------------------------------
    |
    | Backups are written through Laravel's filesystem. The default "local"
    | disk is rooted at storage/app/private, so the configured path below
    | resolves to storage/app/private/database-backups — never a public disk.
    |
    */

    'enabled' => (bool) env('DB_BACKUP_ENABLED', true),

    'disk' => env('DB_BACKUP_DISK', 'local'),

    'path' => trim((string) env('DB_BACKUP_PATH', 'database-backups'), '/'),

    /*
    | Native utilities write to a local temporary file first. When the target
    | disk is local the dump is written straight to its final location.
    */
    'temporary_path' => trim((string) env('DB_BACKUP_TEMP_PATH', 'database-backups-tmp'), '/'),

    'binaries' => [
        'mysqldump' => env('DB_BACKUP_MYSQL_DUMP_PATH', 'mysqldump'),
        'mysql' => env('DB_BACKUP_MYSQL_CLIENT_PATH', 'mysql'),
        'pg_dump' => env('DB_BACKUP_PG_DUMP_PATH', 'pg_dump'),
        'psql' => env('DB_BACKUP_PSQL_PATH', 'psql'),
        'sqlite3' => env('DB_BACKUP_SQLITE3_PATH', 'sqlite3'),
    ],

    /*
    | Maximum accepted upload size in kilobytes (51200 KB = 50 MB).
    */
    'max_upload_size' => (int) env('DB_BACKUP_MAX_UPLOAD_SIZE', 51200),

    'allowed_extensions' => ['sql'],

    'queue' => env('DB_BACKUP_QUEUE', 'database-backups'),

    'timeouts' => [
        'backup' => (int) env('DB_BACKUP_TIMEOUT', 3600),
        'restore' => (int) env('DB_BACKUP_RESTORE_TIMEOUT', 3600),
    ],

    /*
    | Locks are used to prevent two backups (or two restores) from running at
    | the same time. The TTL is in seconds and must exceed the operation
    | timeout so a crashed worker cannot deadlock the feature forever.
    */
    'locks' => [
        'backup_ttl' => (int) env('DB_BACKUP_LOCK_TTL', 7200),
        'restore_ttl' => (int) env('DB_BACKUP_RESTORE_LOCK_TTL', 7200),
    ],

    'restore' => [
        'enabled' => (bool) env('DB_BACKUP_RESTORE_ENABLED', true),

        /*
        | Take a manual backup before restoring so an accidental restore can
        | still be reversed. A failed safety backup is recorded but does not
        | block the restore unless "require_safety_backup" is true.
        */
        'safety_backup' => (bool) env('DB_BACKUP_RESTORE_SAFETY_BACKUP', true),
        'require_safety_backup' => (bool) env('DB_BACKUP_RESTORE_REQUIRE_SAFETY_BACKUP', false),
        'maintenance_mode' => (bool) env('DB_BACKUP_RESTORE_MAINTENANCE_MODE', false),
    ],

    'schedules' => [
        'daily' => [
            'enabled' => (bool) env('DB_BACKUP_DAILY_ENABLED', true),
            'time' => env('DB_BACKUP_DAILY_TIME', '01:00'),
            'retention' => (int) env('DB_BACKUP_DAILY_RETENTION', 7),
        ],
        'weekly' => [
            'enabled' => (bool) env('DB_BACKUP_WEEKLY_ENABLED', true),
            'time' => env('DB_BACKUP_WEEKLY_TIME', '02:00'),
            // Accepts 0-6 (0 = Sunday) or a day name such as "sunday".
            'day_of_week' => env('DB_BACKUP_WEEKLY_DAY', 0),
            'retention' => (int) env('DB_BACKUP_WEEKLY_RETENTION', 4),
        ],
        'monthly' => [
            'enabled' => (bool) env('DB_BACKUP_MONTHLY_ENABLED', true),
            'time' => env('DB_BACKUP_MONTHLY_TIME', '03:00'),
            'day_of_month' => (int) env('DB_BACKUP_MONTHLY_DAY', 1),
            'retention' => (int) env('DB_BACKUP_MONTHLY_RETENTION', 12),
        ],
    ],

    'timezone' => env('DB_BACKUP_TIMEZONE', 'UTC'),

    'notifications' => [
        'email' => env('DB_BACKUP_NOTIFICATION_EMAIL'),
    ],

];
