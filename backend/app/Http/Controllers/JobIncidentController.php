<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobIncident;
use App\Services\Jobs\JobIncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JobIncidentController extends Controller
{
    public function store(Request $request, Job $job, JobIncidentService $incidents): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in([
                'vehicle_breakdown',
                'accident',
                'access_issue',
                'dealer_unavailable',
                'wrong_address',
                'other',
            ])],
            'recovery_required' => ['boolean'],
            'vehicle_safe' => ['nullable', 'boolean'],
            'blocking_road' => ['nullable', 'boolean'],
            'location_label' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $incidents->report($job, $request->user(), $validated),
            'message' => 'Issue reported. The dealer has been notified.',
        ], 201);
    }

    public function recoverySent(Request $request, Job $job, JobIncident $incident, JobIncidentService $incidents): JsonResponse
    {
        if ((int) $incident->job_id !== (int) $job->id) {
            abort(404);
        }

        return response()->json([
            'data' => $incidents->markRecoverySent($job, $incident, $request->user()),
            'message' => 'Recovery marked as sent. The driver has been notified.',
        ]);
    }
}
