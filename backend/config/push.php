<?php

return [
    'apns' => [
        'enabled' => env('APNS_ENABLED', false),
        'production' => env('APNS_PRODUCTION', true),
        'team_id' => env('APNS_TEAM_ID'),
        'key_id' => env('APNS_KEY_ID'),
        'bundle_id' => env('APNS_BUNDLE_ID', 'com.motorrelay.app'),
        'private_key' => env('APNS_PRIVATE_KEY'),
    ],
];
