<?php

$source = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
$destination = new SQLite3($argv[2]);

if (! $source->backup($destination)) {
    fwrite(STDERR, 'Unable to back up the local database.');
    exit(1);
}

$destination->close();
$source->close();
