<?php

return [
    'mysql_binary' => env('BACKUP_MYSQL_BINARY', 'mysqldump'),
    'pgsql_binary' => env('BACKUP_PGSQL_BINARY', 'pg_dump'),

    /*
     * Where backup:before-deploy uploads a backup before migrations run (use s3 in production).
     */
    'disk' => env('BACKUP_DISK', 'local'),
    'keep' => (int) env('BACKUP_KEEP', 10),
];
