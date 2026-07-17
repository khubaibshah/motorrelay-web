<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PostcodeLookupService
{
    public function find(string $postcode): array
    {
        $input = trim(preg_replace('/\s+/', ' ', $postcode) ?? '');

        if ($input === '') {
            abort(422, 'Enter a postcode, address, or business name.');
        }

        $apiKey = trim((string) config('postcodes.api_key'));
        if (! $apiKey) {
            abort(500, 'Postcode lookup API key is not configured.');
        }

        $addresses = $this->mergePlaceResults(
            $this->findPlacesNew($input, $apiKey),
            $this->findPlaces($input, $apiKey, 'address'),
            $this->findPlaces($input, $apiKey, 'establishment'),
            $this->findPlaces($input.', UK', $apiKey, 'geocode')
        );

        if (empty($addresses)) {
            $addresses = $this->findPostcodeViaGeocoding($input, $apiKey);
        }

        if (empty($addresses)) {
            abort(422, 'No UK addresses or businesses found. Try a full address, business name, town, or postcode.');
        }

        return [
            'postcode' => $this->looksLikePostcode($input) ? $this->normalisePostcode($input) : null,
            'query' => $input,
            'addresses' => $addresses,
        ];
    }

    public function resolve(string $placeId): array
    {
        $placeId = trim($placeId);

        if ($placeId === '') {
            abort(422, 'Select an address first.');
        }

        $apiKey = trim((string) config('postcodes.api_key'));
        if (! $apiKey) {
            abort(500, 'Postcode lookup API key is not configured.');
        }

        if (str_starts_with($placeId, 'places/')) {
            return $this->resolveNewPlace($placeId, $apiKey);
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->get(rtrim((string) config('postcodes.details_url'), '/'), [
                'place_id' => $placeId,
                'fields' => 'formatted_address,address_component,geometry,place_id',
                'language' => 'en-GB',
                'key' => $apiKey,
            ]);

        $payload = $response->json() ?? [];
        if (($payload['status'] ?? 'OK') !== 'OK') {
            abort(422, sprintf(
                'Address lookup failed. %s',
                trim((string) ($payload['error_message'] ?? $payload['status'] ?? 'Check the selected address and Google Maps API key.'))
            ));
        }

        if (! $response->successful()) {
            $message = $response->json('error_message')
                ?? $response->json('message')
                ?? $response->body();

            abort(422, sprintf(
                'Address lookup failed (%s). %s',
                $response->status(),
                trim(substr((string) $message, 0, 180)) ?: 'Check the address selection and Google Maps API key.'
            ));
        }

        $payload = $payload['result'] ?? [];
        if (empty($payload)) {
            abort(422, 'No address details were returned for this selection.');
        }

        $postcode = $this->extractPostcode($payload['address_components'] ?? []);
        $latitude = data_get($payload, 'geometry.location.lat');
        $longitude = data_get($payload, 'geometry.location.lng');

        return [
            'place_id' => (string) ($payload['place_id'] ?? $placeId),
            'postcode' => $postcode,
            'label' => (string) ($payload['formatted_address'] ?? ''),
            'latitude' => is_numeric($latitude) ? (float) $latitude : null,
            'longitude' => is_numeric($longitude) ? (float) $longitude : null,
        ];
    }

    public function coordinates(string $postcode): array
    {
        $input = trim(preg_replace('/\s+/', ' ', $postcode) ?? '');
        $normalisedPostcode = $this->normalisePostcode($input);
        $isPostcode = $this->looksLikePostcode($input);

        if ($input === '') {
            abort(422, 'Enter a postcode, address, or business name first.');
        }

        $apiKey = trim((string) config('postcodes.api_key'));
        if (! $apiKey) {
            abort(500, 'Postcode lookup API key is not configured.');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->get(rtrim((string) config('postcodes.geocode_url'), '/'), [
                'address' => $isPostcode ? $normalisedPostcode : $input,
                'components' => $isPostcode ? 'country:GB|postal_code:'.$normalisedPostcode : 'country:GB',
                'language' => 'en-GB',
                'key' => $apiKey,
            ]);

        $this->assertGoogleResponseSucceeded($response, 'Location lookup');

        $payload = $response->json() ?? [];
        $status = $payload['status'] ?? 'OK';

        if ($status === 'ZERO_RESULTS') {
            abort(422, 'No UK location found for that search.');
        }

        if ($status !== 'OK') {
            abort(422, sprintf(
                'Location lookup failed. %s',
                trim((string) ($payload['error_message'] ?? $status ?? 'Check the location and Google Maps API key.'))
            ));
        }

        $result = collect($payload['results'] ?? [])->first(fn ($item) => is_array($item) && data_get($item, 'geometry.location.lat') && data_get($item, 'geometry.location.lng'));
        $latitude = data_get($result, 'geometry.location.lat');
        $longitude = data_get($result, 'geometry.location.lng');

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            abort(422, 'No coordinates were returned for that location.');
        }

        $resolvedPostcode = $this->extractPostcode(data_get($result, 'address_components', []));

        return [
            'postcode' => $resolvedPostcode ?: ($isPostcode ? $normalisedPostcode : null),
            'outward_code' => $resolvedPostcode ? $this->outwardCode($resolvedPostcode) : ($isPostcode ? $this->outwardCode($normalisedPostcode) : null),
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'label' => (string) data_get($result, 'formatted_address', $input),
        ];
    }

    public function reverse(float $latitude, float $longitude): array
    {
        $apiKey = trim((string) config('postcodes.api_key'));
        if (! $apiKey) {
            abort(500, 'Postcode lookup API key is not configured.');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->get(rtrim((string) config('postcodes.geocode_url'), '/'), [
                'latlng' => $latitude.','.$longitude,
                'language' => 'en-GB',
                'key' => $apiKey,
            ]);

        $this->assertGoogleResponseSucceeded($response, 'Current location lookup');

        $payload = $response->json() ?? [];
        $status = $payload['status'] ?? 'OK';
        if ($status === 'ZERO_RESULTS') {
            abort(422, 'No postcode was found for your current location.');
        }
        if ($status !== 'OK') {
            abort(422, sprintf(
                'Current location lookup failed. %s',
                trim((string) ($payload['error_message'] ?? $status))
            ));
        }

        $results = collect($payload['results'] ?? [])->filter(fn ($item) => is_array($item))->values();
        $result = $results->first(fn ($item) => $this->extractPostcode($item['address_components'] ?? []) !== null)
            ?? $results->first();

        if (! $result) {
            abort(422, 'No nearby location was found for your current location.');
        }

        $postcode = $this->extractPostcode($result['address_components'] ?? []);
        $label = (string) ($result['formatted_address'] ?? '');
        $locality = $this->extractLocality($result['address_components'] ?? []);

        return [
            'postcode' => $postcode,
            'outward_code' => $postcode ? $this->outwardCode($postcode) : null,
            'label' => $label !== '' ? $label : ($postcode ?: $locality ?: 'Current location'),
            'locality' => $locality,
        ];
    }

    protected function normalisePostcode(string $postcode): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $postcode) ?? ''));
    }

    protected function looksLikePostcode(string $value): bool
    {
        $input = $this->normalisePostcode($value);

        return (bool) preg_match('/^[A-Z]{1,2}\d[A-Z\d]?(?:\s*\d[A-Z]{2})?$/', $input);
    }

    protected function outwardCode(string $postcode): string
    {
        $postcode = $this->normalisePostcode($postcode);

        if (str_contains($postcode, ' ')) {
            return trim(explode(' ', $postcode)[0]);
        }

        return strlen($postcode) > 3 ? substr($postcode, 0, -3) : $postcode;
    }

    protected function findPlaces(string $input, string $apiKey, string $type): array
    {
        $response = Http::acceptJson()
            ->timeout(15)
            ->get(rtrim((string) config('postcodes.autocomplete_url'), '/'), [
                'input' => $input,
                'components' => 'country:gb',
                'types' => $type,
                'language' => 'en-GB',
                'key' => $apiKey,
            ]);

        $this->assertGoogleResponseSucceeded($response, 'Postcode lookup');

        $payload = $response->json() ?? [];
        $status = $payload['status'] ?? 'OK';

        if ($status === 'ZERO_RESULTS') {
            return [];
        }

        if ($status !== 'OK') {
            abort(422, sprintf(
                'Postcode lookup failed. %s',
                trim((string) ($payload['error_message'] ?? $status ?? 'Check the postcode and Google Maps API key.'))
            ));
        }

        return collect($payload['predictions'] ?? [])
            ->filter(fn ($prediction) => is_array($prediction) && ! empty($prediction['place_id']) && ! empty($prediction['description']))
            ->values()
            ->map(fn (array $prediction) => [
                'id' => (string) ($prediction['place_id'] ?? ''),
                'label' => (string) ($prediction['description'] ?? ''),
                'secondary' => (string) data_get($prediction, 'structured_formatting.secondary_text', ''),
            ])
            ->all();
    }

    protected function findPlacesNew(string $input, string $apiKey): array
    {
        $response = Http::acceptJson()
            ->withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => 'suggestions.placePrediction.placeId,suggestions.placePrediction.place,suggestions.placePrediction.text,suggestions.placePrediction.structuredFormat',
            ])
            ->timeout(15)
            ->post(rtrim((string) config('postcodes.places_new_autocomplete_url'), '/'), [
                'input' => $input,
                'includedRegionCodes' => ['gb'],
                'includePureServiceAreaBusinesses' => true,
                'languageCode' => 'en-GB',
            ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('suggestions') ?? [])
            ->map(fn ($suggestion) => $suggestion['placePrediction'] ?? null)
            ->filter(fn ($prediction) => is_array($prediction) && (! empty($prediction['place']) || ! empty($prediction['placeId'])))
            ->values()
            ->map(function (array $prediction) {
                $place = (string) ($prediction['place'] ?? '');
                $placeId = $place !== '' ? $place : 'places/'.((string) ($prediction['placeId'] ?? ''));
                $label = (string) data_get($prediction, 'text.text', '');
                $mainText = (string) data_get($prediction, 'structuredFormat.mainText.text', '');
                $secondaryText = (string) data_get($prediction, 'structuredFormat.secondaryText.text', '');

                return [
                    'id' => $placeId,
                    'label' => $label !== '' ? $label : trim($mainText.', '.$secondaryText, ', '),
                    'secondary' => $secondaryText,
                ];
            })
            ->filter(fn ($prediction) => $prediction['id'] !== '' && $prediction['label'] !== '')
            ->all();
    }

    protected function resolveNewPlace(string $placeName, string $apiKey): array
    {
        $placeResourceId = str_starts_with($placeName, 'places/')
            ? substr($placeName, strlen('places/'))
            : $placeName;

        $response = Http::acceptJson()
            ->withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => 'id,formattedAddress,addressComponents,location',
            ])
            ->timeout(15)
            ->get(rtrim((string) config('postcodes.places_new_details_url'), '/').'/'.$placeResourceId, [
                'languageCode' => 'en-GB',
            ]);

        if (! $response->successful()) {
            $message = $response->json('error.message')
                ?? $response->json('message')
                ?? $response->body();

            abort(422, sprintf(
                'Address lookup failed (%s). %s',
                $response->status(),
                trim(substr((string) $message, 0, 180)) ?: 'Check the address selection and Google Maps API key.'
            ));
        }

        $payload = $response->json() ?? [];
        if (empty($payload)) {
            abort(422, 'No address details were returned for this selection.');
        }

        $postcode = $this->extractPostcodeFromNewComponents($payload['addressComponents'] ?? []);
        $latitude = data_get($payload, 'location.latitude');
        $longitude = data_get($payload, 'location.longitude');

        return [
            'place_id' => (string) ($payload['id'] ?? $placeName),
            'postcode' => $postcode,
            'label' => (string) ($payload['formattedAddress'] ?? ''),
            'latitude' => is_numeric($latitude) ? (float) $latitude : null,
            'longitude' => is_numeric($longitude) ? (float) $longitude : null,
        ];
    }

    protected function mergePlaceResults(array ...$groups): array
    {
        return collect($groups)
            ->flatten(1)
            ->unique('id')
            ->values()
            ->all();
    }

    protected function findPostcodeViaGeocoding(string $postcode, string $apiKey): array
    {
        $response = Http::acceptJson()
            ->timeout(15)
            ->get(rtrim((string) config('postcodes.geocode_url'), '/'), [
                'address' => $postcode,
                'components' => $this->looksLikePostcode($postcode) ? 'country:GB|postal_code:'.$this->normalisePostcode($postcode) : 'country:GB',
                'language' => 'en-GB',
                'key' => $apiKey,
            ]);

        $this->assertGoogleResponseSucceeded($response, 'Postcode lookup');

        $payload = $response->json() ?? [];
        $status = $payload['status'] ?? 'OK';

        if ($status === 'ZERO_RESULTS') {
            return [];
        }

        if ($status !== 'OK') {
            abort(422, sprintf(
                'Postcode lookup failed. %s',
                trim((string) ($payload['error_message'] ?? $status ?? 'Check the postcode and Google Maps API key.'))
            ));
        }

        return collect($payload['results'] ?? [])
            ->filter(fn ($result) => is_array($result) && ! empty($result['place_id']) && ! empty($result['formatted_address']))
            ->values()
            ->map(fn (array $result) => [
                'id' => (string) ($result['place_id'] ?? ''),
                'label' => (string) ($result['formatted_address'] ?? ''),
                'secondary' => 'Postcode result',
            ])
            ->all();
    }

    protected function assertGoogleResponseSucceeded($response, string $label): void
    {
        if ($response->status() === 404) {
            abort(422, 'No addresses found for this postcode. Use a full UK postcode and try again.');
        }

        if ($response->successful()) {
            return;
        }

        $message = $response->json('Message')
            ?? $response->json('message')
            ?? $response->json('error')
            ?? $response->json('error_message')
            ?? $response->body();

        abort(422, sprintf(
            '%s failed (%s). %s',
            $label,
            $response->status(),
            trim(substr((string) $message, 0, 180)) ?: 'Check the postcode and Google Maps API key.'
        ));
    }

    protected function extractPostcode(array $components): ?string
    {
        foreach ($components as $component) {
            $types = $component['types'] ?? [];
            if (is_array($types) && in_array('postal_code', $types, true)) {
                $postcode = strtoupper(trim((string) ($component['short_name'] ?? $component['long_name'] ?? '')));

                return $postcode !== '' ? $postcode : null;
            }
        }

        return null;
    }

    protected function extractPostcodeFromNewComponents(array $components): ?string
    {
        foreach ($components as $component) {
            $types = $component['types'] ?? [];
            if (is_array($types) && in_array('postal_code', $types, true)) {
                $postcode = strtoupper(trim((string) ($component['shortText'] ?? $component['longText'] ?? '')));

                return $postcode !== '' ? $postcode : null;
            }
        }

        return null;
    }

    protected function extractLocality(array $components): ?string
    {
        $preferredTypes = ['postal_town', 'locality', 'administrative_area_level_2'];

        foreach ($preferredTypes as $preferredType) {
            foreach ($components as $component) {
                $types = $component['types'] ?? [];
                if (is_array($types) && in_array($preferredType, $types, true)) {
                    $label = trim((string) ($component['long_name'] ?? $component['short_name'] ?? ''));

                    return $label !== '' ? $label : null;
                }
            }
        }

        return null;
    }
}
