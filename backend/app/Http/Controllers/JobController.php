<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Services\Jobs\JobService;
use App\Services\RouteDistanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JobController extends Controller
{
    public function __construct(
        protected JobService $jobs,
        protected RouteDistanceService $routeDistance,
    )
    {
    }

    public function highlights(): JsonResponse
    {
        return response()->json(['jobs' => $this->jobs->highlights()]);
    }

    public function routeDistance(Request $request): JsonResponse
    {
        $coordinates = $request->validate([
            'pickup_latitude' => ['required', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['required', 'numeric', 'between:-180,180'],
            'dropoff_latitude' => ['required', 'numeric', 'between:-90,90'],
            'dropoff_longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json([
            'distance_mi' => $this->routeDistance->drivingMiles(
                $coordinates['pickup_latitude'],
                $coordinates['pickup_longitude'],
                $coordinates['dropoff_latitude'],
                $coordinates['dropoff_longitude'],
            ),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->jobs->paginateForUser($request->user(), [
            'scope' => $request->string('scope')->toString(),
            'status' => $request->string('status')->toString(),
            'marketplace' => $request->string('marketplace')->toString(),
            'latitude' => $request->query('latitude'),
            'longitude' => $request->query('longitude'),
            'nearby_radius_miles' => $request->query('nearby_radius_miles'),
            'nearby_postcode' => $request->string('nearby_postcode')->toString(),
            'search' => $request->string('search')->toString(),
            'per_page' => $request->integer('per_page', 15),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeDealerOrAdmin($user);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'pickup_postcode' => ['required', 'string', 'max:20'],
            'pickup_label' => ['required', 'string', 'max:255'],
            'pickup_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_postcode' => ['required', 'string', 'max:20'],
            'dropoff_label' => ['required', 'string', 'max:255'],
            'dropoff_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'vehicle_make' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'transport_type' => ['required', Rule::in(['drive_away', 'trailer'])],
            'listing_type' => ['required', Rule::in(['private', 'auction'])],
            'auction_reference' => [Rule::requiredIf(fn () => $request->input('listing_type') === 'auction'), 'nullable', 'string', 'max:100'],
            'auction_assessment_report' => [Rule::requiredIf(fn () => $request->input('listing_type') === 'auction'), 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:'.config('invoices.proof_max_size_kb')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'pickup_ready_at' => ['nullable', 'date'],
            'delivery_due_at' => ['nullable', 'date'],
        ]);

        return response()->json($this->jobs->create($user, $data), 201);
    }

    public function show(Request $request, Job $job): JsonResponse
    {
        return response()->json($this->jobs->showForUser($job, $request->user()));
    }

    public function update(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if (! $user || (! $user->isAdmin() && $job->posted_by_id !== $user->id)) {
            abort(403, 'You cannot update this job.');
        }

        // Once a driver is assigned, the route, price and vehicle details are
        // locked so both parties work from the same agreed job information.
        if (! $user->isAdmin() && $job->assigned_to_id !== null) {
            abort(409, 'This run is locked because a driver has been assigned.');
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'pickup_postcode' => ['sometimes', 'string', 'max:20'],
            'pickup_label' => ['sometimes', 'string', 'max:255'],
            'pickup_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_postcode' => ['sometimes', 'string', 'max:20'],
            'dropoff_label' => ['sometimes', 'string', 'max:255'],
            'dropoff_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'vehicle_make' => ['nullable', 'string', 'max:255'],
            'transport_type' => ['sometimes', Rule::in(['drive_away', 'trailer'])],
            'listing_type' => ['sometimes', Rule::in(['private', 'auction'])],
            'auction_reference' => [Rule::requiredIf(fn () => $request->input('listing_type') === 'auction'), 'nullable', 'string', 'max:100'],
            'auction_assessment_report' => [
                Rule::requiredIf(fn () => $request->input('listing_type') === 'auction' && ! $job->auction_assessment_report_path),
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:'.config('invoices.proof_max_size_kb'),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'pickup_ready_at' => ['sometimes', 'nullable', 'date'],
            'delivery_due_at' => ['sometimes', 'nullable', 'date'],
        ]);

        return response()->json($this->jobs->update($job, $user, $data));
    }

    public function destroy(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if (! $user || (! $user->isAdmin() && $job->posted_by_id !== $user->id)) {
            abort(403, 'You cannot delete this job.');
        }

        $this->jobs->delete($job);

        return response()->noContent();
    }

    private function authorizeDealerOrAdmin($user): void
    {
        if (! $user || (! $user->isDealer() && ! $user->isAdmin())) {
            abort(403, 'Only dealers or admins can create jobs.');
        }
    }
}
