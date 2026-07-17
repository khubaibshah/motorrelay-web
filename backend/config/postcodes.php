<?php

return [
    'places_new_autocomplete_url' => env('GOOGLE_PLACES_NEW_AUTOCOMPLETE_URL', 'https://places.googleapis.com/v1/places:autocomplete'),
    'places_new_details_url' => env('GOOGLE_PLACES_NEW_DETAILS_URL', 'https://places.googleapis.com/v1/places'),
    'autocomplete_url' => env('POSTCODE_LOOKUP_AUTOCOMPLETE_URL', 'https://maps.googleapis.com/maps/api/place/autocomplete/json'),
    'details_url' => env('POSTCODE_LOOKUP_DETAILS_URL', 'https://maps.googleapis.com/maps/api/place/details/json'),
    'geocode_url' => env('POSTCODE_LOOKUP_GEOCODE_URL', 'https://maps.googleapis.com/maps/api/geocode/json'),
    'api_key' => env('GOOGLE_MAPS_API_KEY', env('POSTCODE_LOOKUP_API_KEY')),
];
