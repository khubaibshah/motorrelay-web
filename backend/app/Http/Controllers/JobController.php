<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobDailyMetric;
use App\Notifications\JobStatusNotification;
use App\Services\VehicleLookupService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class JobController extends Controller
{
    public function highlights(Request $request): JsonResponse
    {
        $jobs = Job::query()
            ->where('status', 'open')
            ->where('payment_status', 'paid')
            ->where(function ($builder) {
                $builder
                    ->whereNull('goes_live_at')
                    ->orWhere('goes_live_at', '<=', now());
            })
            ->orderByDesc('created_at')
            ->limit(6)
            ->get(['id', 'price', 'pickup_label', 'dropoff_label', 'status']);

        return response()->json([
            'jobs' => $jobs,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = $request->string('scope')->toString();
        $status = $request->string('status')->toString();

        $query = Job::query()->with([
            'postedBy:id,name',
            'assignedTo:id,name',
            'finalizedInvoice:id,job_id,status,number,total,currency,issued_at',
        ]);

        if ($user) {
            $query->visibleTo($user);

            if ($user->isDriver()) {
                $query->with(['applications' => function ($builder) use ($user) {
                    $builder->where('driver_id', $user->id);
                }]);
            }
        }

        if ($scope === 'available') {
            $query->where('status', 'open')->whereNull('assigned_to_id');
            if ($user?->isDriver()) {
                $query->where('payment_status', 'paid');
            }
        } elseif ($scope === 'current') {
            $query->whereIn('status', ['in_progress', 'accepted', 'collected', 'in_transit', 'pending', 'completion_pending'])
                ->when(! $user?->isAdmin(), function ($inner) use ($user) {
                    $inner->where(function ($child) use ($user) {
                        $child->where('assigned_to_id', $user->id)
                            ->orWhere('posted_by_id', $user->id);
                    });
                });
        } elseif ($scope === 'completed') {
            $query->whereIn('status', ['completed', 'delivered', 'cancelled', 'closed'])
                ->when(! $user?->isAdmin(), function ($inner) use ($user) {
                    $inner->where(function ($child) use ($user) {
                        $child->where('assigned_to_id', $user->id)
                            ->orWhere('posted_by_id', $user->id);
                    });
                });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('pickup_postcode', 'like', "%{$search}%")
                    ->orWhere('dropoff_postcode', 'like', "%{$search}%");
            });
        }

        if (! $user || ! $user->isAdmin()) {
            $query->where(function ($builder) use ($user) {
                $builder
                    ->whereNull('goes_live_at')
                    ->orWhere('goes_live_at', '<=', now());

                if ($user) {
                    $builder->orWhere('posted_by_id', $user->id);
                }
            });
        }

        if ($user && $user->isDriver()) {
            $planSlug = $user->plan_slug ?? Str::slug((string) $user->plan, '_');
            if ($planSlug === 'starter') {
                $radius = config('jobs.plan_limits.starter.job_distance_radius', 50);
                $query->where(function ($builder) use ($radius) {
                    $builder->whereNull('distance_mi')
                        ->orWhere('distance_mi', '<=', $radius);
                });
            }
        }

        $jobs = $query
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 15));

        if ($user && $user->isDriver()) {
            $jobs->getCollection()->transform(function (Job $jobItem) {
                $application = $jobItem->applications->first();
                $jobItem->setRelation('my_application', $application);
                $jobItem->unsetRelation('applications');

                return $jobItem;
            });
        }

        return response()->json($jobs);
    }

    public function store(Request $request, VehicleLookupService $vehicles): JsonResponse
    {
        $user = $request->user();

        if (! $user || (! $user->isDealer() && ! $user->isAdmin())) {
            abort(403, 'Only dealers or admins can create jobs.');
        }

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
            'pickup_ready_at' => ['nullable', 'date'],
            'delivery_due_at' => ['nullable', 'date'],
        ]);

        $vehicle = $vehicles->lookup($data['title']);

        $planSlug = $user->plan_slug ?? Str::slug((string) $user->plan, '_');
        $planLimits = config("jobs.plan_limits.{$planSlug}", []);

        $unlimitedTestAccounts = collect(config('jobs.unlimited_test_accounts', []))
            ->map(fn ($email) => strtolower((string) $email));
        $hasUnlimitedTestAccess = $unlimitedTestAccounts->contains(strtolower((string) $user->email));

        if ($user->isDealer() && ! $user->isAdmin() && ! $hasUnlimitedTestAccess && ! empty($planLimits)) {
            $startOfMonth = Carbon::now()->startOfMonth();
            $monthlyLimit = $planLimits['monthly_job_posts'] ?? null;
            if ($monthlyLimit) {
                $postsThisMonth = Job::where('posted_by_id', $user->id)
                    ->where('created_at', '>=', $startOfMonth)
                    ->count();

                if ($postsThisMonth >= $monthlyLimit) {
                    abort(422, sprintf(
                        'Starter plan allows up to %d jobs per month. Upgrade to add more.',
                        $monthlyLimit
                    ));
                }
            }
        }

        if (! empty($data['pickup_ready_at']) && ! empty($data['delivery_due_at'])) {
            $pickupReady = Carbon::parse($data['pickup_ready_at']);
            $deliveryDue = Carbon::parse($data['delivery_due_at']);

            if ($deliveryDue->lt($pickupReady)) {
                abort(422, 'Delivery due time must be after the pickup ready time.');
            }
        }

        $pickupReadyAt = ! empty($data['pickup_ready_at']) ? Carbon::parse($data['pickup_ready_at']) : null;
        $deliveryDueAt = ! empty($data['delivery_due_at']) ? Carbon::parse($data['delivery_due_at']) : null;

        $distanceMiles = $this->calculateDistanceMiles(
            $data['pickup_latitude'] ?? null,
            $data['pickup_longitude'] ?? null,
            $data['dropoff_latitude'] ?? null,
            $data['dropoff_longitude'] ?? null
        );

        $jobPrice = (float) $data['price'];
        if ($jobPrice <= 0 && $distanceMiles !== null) {
            $jobPrice = $this->suggestedPrice($distanceMiles, $data['transport_type']);
        }

        $job = Job::create([
            'status' => 'open',
            'posted_by_id' => $user->id,
            'title' => $vehicle['registration'],
            'pickup_postcode' => $data['pickup_postcode'],
            'pickup_label' => $data['pickup_label'],
            'pickup_latitude' => $data['pickup_latitude'] ?? null,
            'pickup_longitude' => $data['pickup_longitude'] ?? null,
            'dropoff_postcode' => $data['dropoff_postcode'],
            'dropoff_label' => $data['dropoff_label'],
            'dropoff_latitude' => $data['dropoff_latitude'] ?? null,
            'dropoff_longitude' => $data['dropoff_longitude'] ?? null,
            'distance_mi' => $distanceMiles,
            'vehicle_make' => $vehicle['display_name'],
            'vehicle_type' => $vehicle['vehicle_type'],
            'price' => $jobPrice,
            'transport_type' => $data['transport_type'],
            'pickup_ready_at' => $pickupReadyAt,
            'delivery_due_at' => $deliveryDueAt,
            'goes_live_at' => null,
        ]);

        return response()->json($job, 201);
    }

    public function show(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();

        $job->load([
            'postedBy:id,name,email',
            'assignedTo:id,name,email',
            'finalizedInvoice:id,job_id,status,number,total,currency,issued_at',
        ]);

        if ($user) {
            if ($user->isAdmin() || $job->posted_by_id === $user->id) {
                $job->setRelation(
                    'applications',
                    $job->applications()
                        ->with(['driver:id,name,email'])
                        ->orderByRaw("FIELD(status, 'pending', 'accepted', 'declined')")
                        ->latest()
                        ->get()
                );
            } elseif ($user->isDriver()) {
                $job->setRelation(
                    'my_application',
                    $job->applications()
                        ->where('driver_id', $user->id)
                        ->first()
                );
            }
        }

        $isOwner = $user && $job->posted_by_id === $user->id;
        $isAdmin = $user && $user->isAdmin();

        if ($job->goes_live_at && $job->goes_live_at->isFuture() && ! $isOwner && ! $isAdmin) {
            abort(404);
        }

        if ($user && ($user->isAdmin() || $job->posted_by_id === $user->id || $job->assigned_to_id === $user->id)) {
            $expenses = $job->expenses()
                ->with(['driver:id,name'])
                ->orderByDesc('created_at')
                ->get();
            $incidents = $job->incidents()
                ->with(['reportedBy:id,name', 'recoverySentBy:id,name'])
                ->orderByDesc('created_at')
                ->get();

            $job->setRelation('expenses', $expenses);
            $job->setRelation('incidents', $incidents);

            $job->setAttribute('expenses_summary', [
                'submitted_total' => $expenses->where('status', 'submitted')->sum(fn ($expense) => $expense->total_amount),
                'approved_total' => $expenses->where('status', 'approved')->sum(fn ($expense) => $expense->total_amount),
                'rejected_total' => $expenses->where('status', 'rejected')->sum(fn ($expense) => $expense->total_amount),
            ]);
        }

        $this->recordJobView($job);

        if ($isOwner && ($user->plan_slug ?? Str::slug((string) $user->plan, '_')) === 'starter') {
            $job->setAttribute('basic_analytics', $this->basicAnalytics($job));
        }

        return response()->json($job);
    }

    public function update(Request $request, Job $job, VehicleLookupService $vehicles): JsonResponse
    {
        $user = $request->user();

        if (! $user || (! $user->isAdmin() && $job->posted_by_id !== $user->id)) {
            abort(403, 'You cannot update this job.');
        }

        $isOwnerDealer = $user->isDealer() && $job->posted_by_id === $user->id;
        $planSlug = $user->plan_slug ?? Str::slug((string) $user->plan, '_');
        $planLimits = config("jobs.plan_limits.{$planSlug}", []);

        if ($isOwnerDealer && $job->goes_live_at && now()->greaterThan($job->goes_live_at)) {
            abort(422, 'This job is already live and can no longer be edited.');
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
            'pickup_ready_at' => ['sometimes', 'nullable', 'date'],
            'delivery_due_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('pickup_ready_at', $data) && array_key_exists('delivery_due_at', $data)
            && $data['pickup_ready_at'] !== null && $data['delivery_due_at'] !== null) {
            $pickupReady = Carbon::parse($data['pickup_ready_at']);
            $deliveryDue = Carbon::parse($data['delivery_due_at']);

            if ($deliveryDue->lt($pickupReady)) {
                abort(422, 'Delivery due time must be after the pickup ready time.');
            }
        }

        $updates = $data;

        if (array_key_exists('title', $updates)) {
            $vehicle = $vehicles->lookup($updates['title']);
            $updates['title'] = $vehicle['registration'];
            $updates['vehicle_make'] = $vehicle['display_name'];
            $updates['vehicle_type'] = $vehicle['vehicle_type'];
        } else {
            unset($updates['vehicle_make']);
        }

        if (array_key_exists('pickup_postcode', $updates) && ! array_key_exists('pickup_label', $updates)) {
            $updates['pickup_label'] = $updates['pickup_postcode'];
        }

        if (array_key_exists('dropoff_postcode', $updates) && ! array_key_exists('dropoff_label', $updates)) {
            $updates['dropoff_label'] = $updates['dropoff_postcode'];
        }

        if (array_key_exists('pickup_ready_at', $updates)) {
            $updates['pickup_ready_at'] = $updates['pickup_ready_at'] ? Carbon::parse($updates['pickup_ready_at']) : null;
        }

        if (array_key_exists('delivery_due_at', $updates)) {
            $updates['delivery_due_at'] = $updates['delivery_due_at'] ? Carbon::parse($updates['delivery_due_at']) : null;
        }

        $pickupLatitude = $updates['pickup_latitude'] ?? $job->pickup_latitude;
        $pickupLongitude = $updates['pickup_longitude'] ?? $job->pickup_longitude;
        $dropoffLatitude = $updates['dropoff_latitude'] ?? $job->dropoff_latitude;
        $dropoffLongitude = $updates['dropoff_longitude'] ?? $job->dropoff_longitude;
        $updates['distance_mi'] = $this->calculateDistanceMiles(
            $pickupLatitude,
            $pickupLongitude,
            $dropoffLatitude,
            $dropoffLongitude
        );

        $job->update($updates);

        $recipients = collect();

        if ($job->assignedTo) {
            $recipients->push($job->assignedTo);
        } else {
            $recipients = $job->applications()
                ->whereIn('status', ['pending', 'accepted'])
                ->with('driver:id,name')
                ->get()
                ->pluck('driver')
                ->filter();
        }

        $recipients = $recipients->unique('id')->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new JobStatusNotification($job->fresh(['postedBy:id,name', 'assignedTo:id,name']), 'dealer_updated_job', [
                'changed_fields' => array_keys($updates),
            ]));
        }

        return response()->json($job);
    }

    public function destroy(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if (! $user || (! $user->isAdmin() && $job->posted_by_id !== $user->id)) {
            abort(403, 'You cannot delete this job.');
        }

        $job->delete();

        return response()->noContent();
    }

    protected function recordJobView(Job $job): void
    {
        $today = Carbon::today()->toDateString();

        $metric = JobDailyMetric::firstOrCreate(
            [
                'job_id' => $job->id,
                'date' => $today,
            ],
            [
                'views' => 0,
            ]
        );

        $metric->increment('views');
    }

    protected function basicAnalytics(Job $job): array
    {
        $today = Carbon::today();
        $windowStart = $today->copy()->subDays(6);

        $metrics = JobDailyMetric::query()
            ->where('job_id', $job->id)
            ->whereDate('date', '>=', $windowStart->toDateString())
            ->orderBy('date')
            ->get();

        $viewsToday = (int) optional(
            $metrics->firstWhere('date', $today->toDateString())
        )->views ?? 0;

        $viewsLastSeven = (int) $metrics->sum('views');

        return [
            'views_today' => $viewsToday,
            'views_last_7_days' => $viewsLastSeven,
            'daily' => $metrics->map(fn (JobDailyMetric $metric) => [
                'date' => $metric->date,
                'views' => (int) $metric->views,
            ])->values(),
        ];
    }

    protected function calculateDistanceMiles(mixed $pickupLatitude, mixed $pickupLongitude, mixed $dropoffLatitude, mixed $dropoffLongitude): ?float
    {
        if (! is_numeric($pickupLatitude) || ! is_numeric($pickupLongitude) || ! is_numeric($dropoffLatitude) || ! is_numeric($dropoffLongitude)) {
            return null;
        }

        $earthRadiusMiles = 3958.7613;
        $pickupLat = deg2rad((float) $pickupLatitude);
        $pickupLng = deg2rad((float) $pickupLongitude);
        $dropoffLat = deg2rad((float) $dropoffLatitude);
        $dropoffLng = deg2rad((float) $dropoffLongitude);

        $latDelta = $dropoffLat - $pickupLat;
        $lngDelta = $dropoffLng - $pickupLng;

        $a = sin($latDelta / 2) ** 2
            + cos($pickupLat) * cos($dropoffLat) * sin($lngDelta / 2) ** 2;

        $distance = 2 * $earthRadiusMiles * atan2(sqrt($a), sqrt(1 - $a));

        return round($distance, 1);
    }

    protected function suggestedPrice(float $distanceMiles, string $transportType): float
    {
        $pricing = config('jobs.pricing', []);
        $baseFee = (float) ($pricing['base_fee'] ?? 35);
        $minimumPrice = (float) ($pricing['minimum_price'] ?? 75);
        $rate = $transportType === 'trailer'
            ? (float) ($pricing['trailer_rate_per_mile'] ?? 2.5)
            : (float) ($pricing['drive_away_rate_per_mile'] ?? 1.5);

        return round(max($minimumPrice, $baseFee + ($distanceMiles * $rate)), 2);
    }
}
