<?php

return [
    'mysql_binary' => env('BACKUP_MYSQL_BINARY', 'mysqldump'),
    'pgsql_binary' => env('BACKUP_PGSQL_BINARY', 'pg_dump'),
];
