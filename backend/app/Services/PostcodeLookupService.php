<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PostcodeLookupService
{
    public function find(string $postcode): array
    {
        $postcode = $this->normalisePostcode($postcode);

        if ($postcode === '') {
            abort(422, 'Enter a valid postcode.');
        }

        $apiKey = trim((string) config('postcodes.api_key'));
        if (!$apiKey) {
            abort(500, 'Postcode lookup API key is not configured.');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->get(rtrim((string) config('postcodes.autocomplete_url'), '/'), [
                'input' => $postcode,
                'components' => 'country:gb',
                'types' => 'address',
                'language' => 'en-GB',
                'key' => $apiKey,
            ]);

        if ($response->status() === 404) {
            abort(422, 'No addresses found for this postcode. Use a full UK postcode and try again.');
        }

        if (!$response->successful()) {
            $message = $response->json('Message')
                ?? $response->json('message')
                ?? $response->json('error')
                ?? $response->body();

            abort(422, sprintf(
                'Postcode lookup failed (%s). %s',
                $response->status(),
                trim(substr((string) $message, 0, 180)) ?: 'Check the postcode and Google Maps API key.'
            ));
        }

        $payload = $response->json() ?? [];
        if (($payload['status'] ?? 'OK') !== 'OK' && ($payload['status'] ?? null) !== 'ZERO_RESULTS') {
            abort(422, sprintf(
                'Postcode lookup failed. %s',
                trim((string) ($payload['error_message'] ?? $payload['status'] ?? 'Check the postcode and Google Maps API key.'))
            ));
        }

        $addresses = collect($payload['predictions'] ?? [])
            ->filter(fn ($prediction) => is_array($prediction) && !empty($prediction['place_id']) && !empty($prediction['description']))
            ->values()
            ->map(fn (array $prediction) => [
                'id' => (string) ($prediction['place_id'] ?? ''),
                'label' => (string) ($prediction['description'] ?? ''),
                'secondary' => (string) data_get($prediction, 'structured_formatting.secondary_text', ''),
            ])
            ->all();

        if (empty($addresses)) {
            abort(422, 'No addresses found for this postcode. Use a full UK postcode and try again.');
        }

        return [
            'postcode' => $postcode,
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
        if (!$apiKey) {
            abort(500, 'Postcode lookup API key is not configured.');
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

        if (!$response->successful()) {
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

    protected function normalisePostcode(string $postcode): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $postcode) ?? ''));
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
}
