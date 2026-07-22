<?php

return [
    // Google Directions returns road distance rather than straight-line distance.
    'directions_url' => env('GOOGLE_DIRECTIONS_URL', 'https://maps.googleapis.com/maps/api/directions/json'),
    'api_key' => env('GOOGLE_MAPS_API_KEY', env('POSTCODE_LOOKUP_API_KEY')),
    'timeout_seconds' => (int) env('GOOGLE_DIRECTIONS_TIMEOUT', 8),
];
