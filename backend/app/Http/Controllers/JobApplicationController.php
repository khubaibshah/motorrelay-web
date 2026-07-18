<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobApplication;
use App\Services\Jobs\JobApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JobApplicationController extends Controller
{
    public function index(Request $request, Job $job, JobApplicationService $applications): JsonResponse
    {
        $user = $request->user();

        if (!$user || (!$user->isAdmin() && $job->posted_by_id !== $user->id)) {
            abort(403, 'You are not allowed to view applications for this job.');
        }

        return response()->json([
            'data' => $applications->listForJob($job),
        ]);
    }

    public function store(Request $request, Job $job, JobApplicationService $applications): JsonResponse
    {
        $user = $request->user();

        if (!$user || (!$user->isDriver() && !$user->isAdmin())) {
            abort(403, 'Only drivers can apply for jobs.');
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(
            $applications->apply($job, $user, $validated['message'] ?? null),
            201
        );
    }

    public function update(Request $request, Job $job, JobApplication $application, JobApplicationService $applications): JsonResponse
    {
        $user = $request->user();

        if (!$user || (!$user->isAdmin() && $job->posted_by_id !== $user->id)) {
            abort(403, 'Only the posting dealer can update applications.');
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['accepted', 'declined'])],
        ]);

        return response()->json(
            $applications->updateStatus($job, $application, $user, $validated['status'])
        );
    }

    public function destroy(Request $request, Job $job, JobApplication $application, JobApplicationService $applications): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->isDriver(), 403, 'Only drivers can withdraw applications.');

        return response()->json([
            'message' => 'Application withdrawn.',
            'application' => $applications->withdraw($job, $application, $user),
        ]);
    }
}
