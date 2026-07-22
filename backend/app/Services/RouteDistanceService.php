<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RouteDistanceService
{
    /**
     * Return the shortest Google driving distance in miles for two points.
     * A null result lets callers use a local fallback when routing is unavailable.
     */
    public function drivingMiles(
        mixed $pickupLatitude,
        mixed $pickupLongitude,
        mixed $dropoffLatitude,
        mixed $dropoffLongitude,
    ): ?float {
        [$pickupLatitude, $pickupLongitude] = $this->normaliseCoordinates($pickupLatitude, $pickupLongitude);
        [$dropoffLatitude, $dropoffLongitude] = $this->normaliseCoordinates($dropoffLatitude, $dropoffLongitude);

        if (! $this->hasCoordinates($pickupLatitude, $pickupLongitude, $dropoffLatitude, $dropoffLongitude)) {
            return null;
        }

        $apiKey = (string) config('routes.api_key');
        if ($apiKey === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('routes.timeout_seconds', 8))
                ->get((string) config('routes.directions_url'), [
                    'origin' => $this->coordinate($pickupLatitude, $pickupLongitude),
                    'destination' => $this->coordinate($dropoffLatitude, $dropoffLongitude),
                    'mode' => 'driving',
                    'units' => 'imperial',
                    'key' => $apiKey,
                ]);

            if (! $response->successful() || $response->json('status') !== 'OK') {
                Log::warning('Driving route distance unavailable', [
                    'http_status' => $response->status(),
                    'provider_status' => $response->json('status'),
                    'error' => $response->json('error_message'),
                ]);

                return null;
            }

            $meters = collect($response->json('routes.0.legs', []))
                ->sum(fn (array $leg): int => (int) data_get($leg, 'distance.value', 0));

            if ($meters <= 0) {
                return null;
            }

            $miles = round($meters / 1609.344, 1);
            $straightLineMiles = $this->straightLineMiles(
                (float) $pickupLatitude,
                (float) $pickupLongitude,
                (float) $dropoffLatitude,
                (float) $dropoffLongitude,
            );

            // Reject obviously malformed provider responses (for example a
            // bad place coordinate producing thousands of miles in the UK).
            // Callers then use the validated straight-line fallback.
            if ($straightLineMiles !== null && $miles > max($straightLineMiles * 3, $straightLineMiles + 50)) {
                Log::warning('Driving route distance looked unreasonable', [
                    'route_miles' => $miles,
                    'straight_line_miles' => $straightLineMiles,
                ]);

                return null;
            }

            return $miles;
        } catch (\Throwable $exception) {
            Log::warning('Driving route distance request failed', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * Correct the common UK latitude/longitude reversal before routing.
     * UK latitude is roughly 49–61 and longitude roughly -9–2.
     */
    public function normaliseCoordinates(mixed $latitude, mixed $longitude): array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return [$latitude, $longitude];
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        $isUkPair = $latitude >= 49 && $latitude <= 61 && $longitude >= -11 && $longitude <= 3;
        $isReversedUkPair = $longitude >= 49 && $longitude <= 61 && $latitude >= -11 && $latitude <= 3;

        if (! $isUkPair && $isReversedUkPair) {
            return [$longitude, $latitude];
        }

        return [$latitude, $longitude];
    }

    protected function hasCoordinates(mixed ...$coordinates): bool
    {
        return collect($coordinates)->every(fn (mixed $coordinate): bool => is_numeric($coordinate));
    }

    protected function coordinate(mixed $latitude, mixed $longitude): string
    {
        return sprintf('%.7F,%.7F', (float) $latitude, (float) $longitude);
    }

    protected function straightLineMiles(float $pickupLatitude, float $pickupLongitude, float $dropoffLatitude, float $dropoffLongitude): ?float
    {
        $earthRadiusMiles = 3958.7613;
        $pickupLat = deg2rad($pickupLatitude);
        $dropoffLat = deg2rad($dropoffLatitude);
        $latDelta = $dropoffLat - $pickupLat;
        $lngDelta = deg2rad($dropoffLongitude - $pickupLongitude);
        $a = sin($latDelta / 2) ** 2
            + cos($pickupLat) * cos($dropoffLat) * sin($lngDelta / 2) ** 2;

        return round(2 * $earthRadiusMiles * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }
}
