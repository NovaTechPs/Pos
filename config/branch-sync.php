<?php

return [
    'mode' => env('BRANCH_SYNC_MODE', 'central'),
    'server_url' => env('BRANCH_SYNC_SERVER_URL', 'https://waslapos.app'),
    'token' => env('BRANCH_SYNC_TOKEN'),
    'tenant_id' => env('BRANCH_SYNC_TENANT_ID'),
    'branch_id' => env('BRANCH_SYNC_BRANCH_ID'),
];
