<?php

namespace App\Http\Controllers;

use App\Services\PostcodeLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostcodeLookupController extends Controller
{
    public function show(string $postcode, PostcodeLookupService $postcodes): JsonResponse
    {
        return response()->json([
            'data' => $postcodes->find($postcode),
        ]);
    }

    public function resolve(string $placeId, PostcodeLookupService $postcodes): JsonResponse
    {
        return response()->json([
            'data' => $postcodes->resolve($placeId),
        ]);
    }

    public function coordinates(string $postcode, PostcodeLookupService $postcodes): JsonResponse
    {
        return response()->json([
            'data' => $postcodes->coordinates($postcode),
        ]);
    }

    public function reverse(Request $request, PostcodeLookupService $postcodes): JsonResponse
    {
        $coordinates = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json([
            'data' => $postcodes->reverse(
                (float) $coordinates['latitude'],
                (float) $coordinates['longitude']
            ),
        ]);
    }
}
