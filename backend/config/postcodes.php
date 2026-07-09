<?php

return [
    'autocomplete_url' => env('POSTCODE_LOOKUP_AUTOCOMPLETE_URL', 'https://maps.googleapis.com/maps/api/place/autocomplete/json'),
    'details_url' => env('POSTCODE_LOOKUP_DETAILS_URL', 'https://maps.googleapis.com/maps/api/place/details/json'),
    'api_key' => env('GOOGLE_MAPS_API_KEY', env('POSTCODE_LOOKUP_API_KEY')),
];
