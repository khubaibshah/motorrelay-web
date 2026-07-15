<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', Rule::in(['ios', 'android', 'web'])],
            'token' => ['required', 'string', 'max:4096'],
            'device_id' => ['nullable', 'string', 'max:255'],
        ]);

        $subscription = $request->user()->pushSubscriptions()->updateOrCreate(
            [
                'platform' => $validated['platform'],
                'token' => $validated['token'],
            ],
            [
                'device_id' => $validated['device_id'] ?? null,
                'last_registered_at' => now(),
            ]
        );

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'platform' => $subscription->platform,
                'last_registered_at' => optional($subscription->last_registered_at)->toIso8601String(),
            ],
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['nullable', Rule::in(['ios', 'android', 'web'])],
            'token' => ['required', 'string', 'max:4096'],
        ]);

        $query = $request->user()->pushSubscriptions()->where('token', $validated['token']);

        if (! empty($validated['platform'])) {
            $query->where('platform', $validated['platform']);
        }

        $query->delete();

        return response()->json(['message' => 'Push subscription removed.']);
    }
}
