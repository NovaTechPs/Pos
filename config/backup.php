<?php

return [
    'mysql_binary' => env('BACKUP_MYSQL_BINARY', 'mysqldump'),
    'pgsql_binary' => env('BACKUP_PGSQL_BINARY', 'pg_dump'),

    /*
     * Where backup:before-deploy uploads a backup before migrations run (use s3 in production).
     */
    'disk' => env('BACKUP_DISK', 'local'),
    'keep' => (int) env('BACKUP_KEEP', 10),

    /*
     * backup:daily keeps this many daily backups. Set BACKUP_DAILY_SCHEDULE=true where a Laravel
     * scheduler runs; on Render, a Cron Job service runs `php artisan backup:daily` once a day instead.
     */
    'daily_keep' => (int) env('BACKUP_DAILY_KEEP', 14),
    'daily_schedule' => (bool) env('BACKUP_DAILY_SCHEDULE', false),
];
