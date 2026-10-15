<?php

return [
    'mode' => env('BRANCH_SYNC_MODE', 'central'),
    'server_url' => env('BRANCH_SYNC_SERVER_URL'),
    'token' => env('BRANCH_SYNC_TOKEN'),
];
