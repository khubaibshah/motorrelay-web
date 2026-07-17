<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Services\Jobs\JobTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobTrackingController extends Controller
{
    public function store(Request $request, Job $job, JobTrackingService $tracking): JsonResponse
    {
        $user = $request->user();

        if (!$user || (!$user->isAdmin() && $job->assigned_to_id !== $user->id)) {
            abort(403, 'Only the assigned driver can share tracking updates.');
        }

        $tracking->ensureTrackingCanBeShared($job);

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'speed_kph' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'heading' => ['nullable', 'numeric', 'min:0', 'max:360'],
            'eta_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($tracking->storeLocation($job, $user, $validated), 201);
    }

    public function requestUpdate(Request $request, Job $job, JobTrackingService $tracking): JsonResponse
    {
        $user = $request->user();

        if (!$user || (!$user->isAdmin() && $job->posted_by_id !== $user->id)) {
            abort(403, 'Only the dealer can request a location update for this run.');
        }

        $tracking->ensureTrackingCanBeRequested($job);
        $tracking->requestLocationUpdate($job, $user);

        return response()->json([
            'message' => 'Location update requested. The driver has been notified.',
        ]);
    }
}
