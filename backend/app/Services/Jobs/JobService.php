<?php

namespace App\Services\Jobs;

use App\Events\JobStatusChanged;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\JobDailyMetric;
use App\Models\User;
use App\Services\AwsS3Service;
use App\Services\VehicleLookupService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class JobService
{
    public function __construct(
        protected VehicleLookupService $vehicles,
        protected AwsS3Service $s3,
    ) {}

    public function highlights()
    {
        return Job::query()
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
    }

    public function paginateForUser(?User $user, array $filters)
    {
        $scope = (string) ($filters['scope'] ?? '');
        $status = (string) ($filters['status'] ?? '');
        $marketplace = (string) ($filters['marketplace'] ?? '');
        $sortedByDriverLocation = false;
        $nearbyRadiusMiles = (float) config('jobs.marketplace_nearby_radius_miles', 25);

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

        $this->applyScope($query, $scope, $user);

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($scope === 'available' && $user?->isDriver() && $marketplace !== 'all') {
            [$sortedByDriverLocation, $nearbyRadiusMiles] = $this->applyNearbyMarketplaceFilter($query, $filters);
        }

        $this->applySearch($query, (string) ($filters['search'] ?? ''));
        $this->applyLiveVisibility($query, $user);
        $this->applyDriverPlanLimits($query, $user, $marketplace);

        $jobs = $query
            ->when(! $sortedByDriverLocation, fn ($builder) => $builder->orderByDesc('created_at'))
            ->paginate((int) ($filters['per_page'] ?? 15));

        if ($user?->isDriver()) {
            $jobs->getCollection()->transform(function (Job $jobItem) use ($user) {
                $application = $jobItem->applications->first();
                $jobItem->setRelation('my_application', $application);
                $jobItem->unsetRelation('applications');

                // Applicants can decide whether to apply, but exact customer locations
                // are only returned after the dealer assigns the run to them.
                if ($jobItem->assigned_to_id !== $user->id) {
                    $this->redactUnassignedDriverJob($jobItem);
                }

                return $jobItem;
            });
        }

        return [
            ...$jobs->toArray(),
            'marketplace' => [
                'nearby_active' => $sortedByDriverLocation,
                'nearby_radius_miles' => $nearbyRadiusMiles,
                'location_used' => $sortedByDriverLocation,
            ],
        ];
    }

    public function create(User $dealer, array $data): Job
    {
        $vehicle = $this->vehicles->lookup($data['title']);

        $this->ensureDealerCanPostJob($dealer);
        $this->ensureDeliveryIsAfterPickup($data);

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
            'posted_by_id' => $dealer->id,
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
            'listing_type' => $data['listing_type'] ?? 'private',
            'auction_reference' => ($data['listing_type'] ?? 'private') === 'auction'
                ? ($data['auction_reference'] ?? null)
                : null,
            'notes' => $data['notes'] ?? null,
            'pickup_ready_at' => $pickupReadyAt,
            'delivery_due_at' => $deliveryDueAt,
            'goes_live_at' => null,
        ]);

        if (($data['listing_type'] ?? 'private') === 'auction' && ($data['auction_assessment_report'] ?? null) instanceof UploadedFile) {
            $this->storeAuctionAssessmentReport($job, $data['auction_assessment_report']);
        }

        return $job->fresh();
    }

    public function showForUser(Job $job, ?User $user): Job
    {
        $job->load([
            'postedBy:id,name,email',
            'assignedTo:id,name,email',
            'finalizedInvoice:id,job_id,status,number,total,currency,issued_at',
            'inspectionPhotos:id,job_id,uploaded_by_id,disk,path,original_name,mime_type,size,sort_order,created_at',
        ]);

        if ($user) {
            if ($user->isAdmin() || $job->posted_by_id === $user->id) {
                $job->setRelation(
                    'applications',
                    $job->applications()
                        ->with(['driver:id,name,email'])
                        ->orderByRaw(sprintf(
                            "FIELD(status, '%s', '%s', '%s', '%s')",
                            JobApplication::STATUS_PENDING,
                            JobApplication::STATUS_ACCEPTED,
                            JobApplication::STATUS_DECLINED,
                            JobApplication::STATUS_WITHDRAWN,
                        ))
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
            $this->loadPrivateJobRelations($job);
        }

        if ($user?->isDriver() && $job->assigned_to_id !== $user->id) {
            $this->redactUnassignedDriverJob($job);
        }

        $this->recordJobView($job);

        return $job;
    }

    public function update(Job $job, User $dealer, array $data): Job
    {
        if ($dealer->isDealer() && $job->posted_by_id === $dealer->id && $job->goes_live_at && now()->greaterThan($job->goes_live_at)) {
            abort(422, 'This job is already live and can no longer be edited.');
        }

        $this->ensureDeliveryIsAfterPickup($data);

        $report = $data['auction_assessment_report'] ?? null;
        unset($data['auction_assessment_report']);
        $reportChanged = $report instanceof UploadedFile;

        $updates = $this->normaliseUpdatePayload($job, $data);
        $job->update($updates);

        if ($reportChanged && ($job->listing_type ?? 'private') === 'auction') {
            $this->storeAuctionAssessmentReport($job, $report);
        } elseif (($updates['listing_type'] ?? null) === 'private') {
            $this->deleteAuctionAssessmentReport($job);
            $job->update([
                'auction_assessment_report_path' => null,
                'auction_assessment_report_disk' => null,
                'auction_assessment_report_name' => null,
            ]);
        }

        $changedFields = array_keys($updates);
        if ($reportChanged) {
            $changedFields[] = 'auction_assessment_report';
        }
        $this->notifyInterestedDrivers($job, array_values(array_unique($changedFields)));

        return $job->fresh();
    }

    public function auctionAssessmentReport(Job $job): array
    {
        if (! $job->auction_assessment_report_path) {
            abort(404, 'No auction assessment report uploaded.');
        }

        $disk = $job->auction_assessment_report_disk ?: config('invoices.auction_assessment_disk');
        $storage = Storage::disk($disk);
        if (! $storage->exists($job->auction_assessment_report_path)) {
            abort(404, 'Assessment report not found.');
        }

        return [$storage, $job->auction_assessment_report_path, $job->auction_assessment_report_name];
    }

    protected function storeAuctionAssessmentReport(Job $job, UploadedFile $file): void
    {
        $disk = config('invoices.auction_assessment_disk');
        // Replace an older report when a dealer edits the auction job. Files stay
        // private and are served through the authorised API endpoint below.
        $this->deleteAuctionAssessmentReport($job);
        $filename = Str::uuid()->toString().'.'.($file->extension() ?: 'bin');
        $path = $this->s3->uploadFile($file, "jobs/{$job->id}/auction-assessment", $filename, $disk, 'private');

        $job->update([
            'auction_assessment_report_path' => $path,
            'auction_assessment_report_disk' => $disk,
            'auction_assessment_report_name' => $file->getClientOriginalName() ?: 'vehicle-assessment-report',
        ]);
    }

    protected function deleteAuctionAssessmentReport(Job $job): void
    {
        if ($job->auction_assessment_report_path) {
            Storage::disk($job->auction_assessment_report_disk ?: config('invoices.auction_assessment_disk'))
                ->delete($job->auction_assessment_report_path);
        }
    }

    public function delete(Job $job): void
    {
        $this->deleteAuctionAssessmentReport($job);
        $job->delete();
    }

    protected function applyScope($query, string $scope, ?User $user): void
    {
        if ($scope === 'available') {
            $query->where('status', 'open')->whereNull('assigned_to_id');

            return;
        }

        if ($scope === 'current') {
            $query->whereIn('status', ['in_progress', 'accepted', 'collected', 'in_transit', 'pending', 'completion_pending'])
                ->when(! $user?->isAdmin(), function ($inner) use ($user) {
                    $inner->where(function ($child) use ($user) {
                        $child->where('assigned_to_id', $user->id)
                            ->orWhere('posted_by_id', $user->id);
                    });
                });

            return;
        }

        if ($scope === 'completed') {
            $query->whereIn('status', ['completed', 'delivered', 'cancelled', 'closed'])
                ->when(! $user?->isAdmin(), function ($inner) use ($user) {
                    $inner->where(function ($child) use ($user) {
                        $child->where('assigned_to_id', $user->id)
                            ->orWhere('posted_by_id', $user->id);
                    });
                });
        }
    }

    /**
     * Keep customer and exact-address data private until a driver is assigned.
     * Coordinates are also removed so clients cannot reconstruct the address.
     */
    protected function redactUnassignedDriverJob(Job $job): void
    {
        $job->setAttribute('pickup_area', $this->locationArea($job->pickup_label, $job->pickup_postcode));
        $job->setAttribute('dropoff_area', $this->locationArea($job->dropoff_label, $job->dropoff_postcode));

        foreach ([
            'pickup_label', 'pickup_postcode', 'pickup_latitude', 'pickup_longitude',
            'dropoff_label', 'dropoff_postcode', 'dropoff_latitude', 'dropoff_longitude',
            'pickup_notes', 'dropoff_notes', 'notes', 'auction_reference',
            'auction_assessment_report_path', 'auction_assessment_report_disk', 'auction_assessment_report_name',
            'company',
        ] as $attribute) {
            $job->setAttribute($attribute, null);
        }

        $job->setRelation('postedBy', null);
    }

    /**
     * Extract a useful town/city label from Google's formatted address without
     * exposing the full street address or postcode to an unassigned driver.
     */
    protected function locationArea(?string $label, ?string $postcode): string
    {
        $label = trim((string) $label);
        $postcode = trim((string) $postcode);
        $label = trim((string) (explode('—', $label, 2)[0] ?? $label));
        $parts = array_values(array_filter(array_map('trim', preg_split('/,/', $label) ?: [])));
        $postcodePattern = '/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i';

        foreach ($parts as $part) {
            if (preg_match($postcodePattern, $part)) {
                $area = trim(preg_replace($postcodePattern, '', $part) ?? '');
                $area = trim($area, " -–—");
                if ($area !== '') {
                    return $area;
                }
            }
        }

        $fallback = collect(array_reverse($parts))
            ->first(fn ($part) => ! preg_match('/\b(?:UK|United Kingdom)\b/i', $part)) ?: $postcode;
        $fallback = trim(preg_replace('/\b(?:UK|United Kingdom)\b/i', '', (string) $fallback) ?? '');

        return trim($fallback, " -–—") ?: ($postcode !== '' ? $this->outwardPostcode($postcode) : 'Location withheld');
    }

    protected function applyNearbyMarketplaceFilter($query, array $filters): array
    {
        if (! is_numeric($filters['latitude'] ?? null) || ! is_numeric($filters['longitude'] ?? null)) {
            return [false, (float) config('jobs.marketplace_nearby_radius_miles', 25)];
        }

        $driverLatitude = (float) $filters['latitude'];
        $driverLongitude = (float) $filters['longitude'];
        $nearbyRadiusMiles = min(
            max((float) ($filters['nearby_radius_miles'] ?? config('jobs.marketplace_nearby_radius_miles', 25)), 1),
            250
        );
        $nearbyOutwardCode = $this->outwardPostcode((string) ($filters['nearby_postcode'] ?? ''));

        if ($driverLatitude < -90 || $driverLatitude > 90 || $driverLongitude < -180 || $driverLongitude > 180) {
            return [false, $nearbyRadiusMiles];
        }

        $distanceSql = '(3958.7613 * acos(least(1, greatest(-1, cos(radians(?)) * cos(radians(pickup_latitude)) * cos(radians(pickup_longitude) - radians(?)) + sin(radians(?)) * sin(radians(pickup_latitude))))))';
        $distanceBindings = [$driverLatitude, $driverLongitude, $driverLatitude];

        $query
            ->select('jobs.*')
            ->selectRaw("{$distanceSql} as driver_distance_mi", $distanceBindings)
            ->where(function ($builder) use ($distanceSql, $distanceBindings, $nearbyRadiusMiles, $nearbyOutwardCode) {
                $builder
                    ->where(function ($distanceBuilder) use ($distanceSql, $distanceBindings, $nearbyRadiusMiles) {
                        $distanceBuilder
                            ->whereNotNull('pickup_latitude')
                            ->whereNotNull('pickup_longitude')
                            ->whereRaw("{$distanceSql} <= ?", [...$distanceBindings, $nearbyRadiusMiles]);
                    });

                if ($nearbyOutwardCode !== '') {
                    $builder->orWhere(function ($postcodeBuilder) use ($nearbyOutwardCode) {
                        $postcodeBuilder
                            ->whereNull('pickup_latitude')
                            ->where('pickup_postcode', 'like', $nearbyOutwardCode.'%');
                    });
                }
            })
            ->orderByRaw('pickup_latitude is null, pickup_longitude is null, driver_distance_mi asc');

        return [true, $nearbyRadiusMiles];
    }

    protected function applySearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function ($builder) use ($search) {
            $builder
                ->where('title', 'like', "%{$search}%")
                ->orWhere('pickup_postcode', 'like', "%{$search}%")
                ->orWhere('dropoff_postcode', 'like', "%{$search}%");
        });
    }

    protected function applyLiveVisibility($query, ?User $user): void
    {
        if ($user?->isAdmin()) {
            return;
        }

        $query->where(function ($builder) use ($user) {
            $builder
                ->whereNull('goes_live_at')
                ->orWhere('goes_live_at', '<=', now());

            if ($user) {
                $builder->orWhere('posted_by_id', $user->id);
            }
        });
    }

    protected function applyDriverPlanLimits($query, ?User $user, string $marketplace): void
    {
        if (! $user?->isDriver()) {
            return;
        }

        $unlimitedTestAccounts = collect(config('jobs.unlimited_test_accounts', []))
            ->map(fn ($email) => strtolower((string) $email));
        $hasUnlimitedTestAccess = $unlimitedTestAccounts->contains(strtolower((string) $user->email));
        $planSlug = $user->plan_slug ?? Str::slug((string) $user->plan, '_');

        if ($planSlug === 'starter' && ! $hasUnlimitedTestAccess && $marketplace !== 'all') {
            $radius = config('jobs.plan_limits.starter.job_distance_radius', 50);
            $query->where(function ($builder) use ($radius) {
                $builder->whereNull('distance_mi')
                    ->orWhere('distance_mi', '<=', $radius);
            });
        }
    }

    protected function ensureDealerCanPostJob(User $dealer): void
    {
        $planSlug = $dealer->plan_slug ?? Str::slug((string) $dealer->plan, '_');
        $planLimits = config("jobs.plan_limits.{$planSlug}", []);

        $unlimitedTestAccounts = collect(config('jobs.unlimited_test_accounts', []))
            ->map(fn ($email) => strtolower((string) $email));
        $hasUnlimitedTestAccess = $unlimitedTestAccounts->contains(strtolower((string) $dealer->email));

        if (! $dealer->isDealer() || $dealer->isAdmin() || $hasUnlimitedTestAccess || empty($planLimits)) {
            return;
        }

        $monthlyLimit = $planLimits['monthly_job_posts'] ?? null;
        if (! $monthlyLimit) {
            return;
        }

        $postsThisMonth = Job::where('posted_by_id', $dealer->id)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->count();

        if ($postsThisMonth >= $monthlyLimit) {
            abort(422, sprintf(
                'Starter plan allows up to %d jobs per month. Upgrade to add more.',
                $monthlyLimit
            ));
        }
    }

    protected function ensureDeliveryIsAfterPickup(array $data): void
    {
        if (empty($data['pickup_ready_at']) || empty($data['delivery_due_at'])) {
            return;
        }

        if (Carbon::parse($data['delivery_due_at'])->lt(Carbon::parse($data['pickup_ready_at']))) {
            abort(422, 'Delivery due time must be after the pickup ready time.');
        }
    }

    protected function normaliseUpdatePayload(Job $job, array $data): array
    {
        $updates = $data;

        if (($updates['listing_type'] ?? null) === 'private') {
            $updates['auction_reference'] = null;
        }

        if (array_key_exists('title', $updates)) {
            $vehicle = $this->vehicles->lookup($updates['title']);
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

        $updates['distance_mi'] = $this->calculateDistanceMiles(
            $updates['pickup_latitude'] ?? $job->pickup_latitude,
            $updates['pickup_longitude'] ?? $job->pickup_longitude,
            $updates['dropoff_latitude'] ?? $job->dropoff_latitude,
            $updates['dropoff_longitude'] ?? $job->dropoff_longitude
        );

        return $updates;
    }

    protected function loadPrivateJobRelations(Job $job): void
    {
        $expenses = $job->expenses()
            ->with(['driver:id,name'])
            ->orderByDesc('created_at')
            ->get();
        $incidents = $job->incidents()
            ->with(['reportedBy:id,name', 'recoverySentBy:id,name', 'recoveryCompletedBy:id,name'])
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

    protected function notifyInterestedDrivers(Job $job, array $changedFields): void
    {
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
            JobStatusChanged::dispatch($job->fresh(['postedBy:id,name', 'assignedTo:id,name']), 'dealer_updated_job', $recipients->pluck('id')->all(), [
                'changed_fields' => $changedFields,
            ]);
        }
    }

    protected function recordJobView(Job $job): void
    {
        $metric = JobDailyMetric::firstOrCreate(
            [
                'job_id' => $job->id,
                'date' => Carbon::today()->toDateString(),
            ],
            [
                'views' => 0,
            ]
        );

        $metric->increment('views');
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

    protected function outwardPostcode(?string $postcode): string
    {
        $postcode = strtoupper(trim(preg_replace('/\s+/', ' ', (string) $postcode) ?? ''));

        if ($postcode === '') {
            return '';
        }

        if (str_contains($postcode, ' ')) {
            return trim(explode(' ', $postcode)[0]);
        }

        return strlen($postcode) > 3 ? substr($postcode, 0, -3) : $postcode;
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
