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

            return $meters > 0 ? round($meters / 1609.344, 1) : null;
        } catch (\Throwable $exception) {
            Log::warning('Driving route distance request failed', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    protected function hasCoordinates(mixed ...$coordinates): bool
    {
        return collect($coordinates)->every(fn (mixed $coordinate): bool => is_numeric($coordinate));
    }

    protected function coordinate(mixed $latitude, mixed $longitude): string
    {
        return sprintf('%.7F,%.7F', (float) $latitude, (float) $longitude);
    }
}
