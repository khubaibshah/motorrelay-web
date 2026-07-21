<?php

namespace App\Http\Controllers;

use App\Models\JobApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->isDriver()) {
            abort(403, 'Only drivers can access this resource.');
        }

        $assignedJobs = $user->assignedJobs()
            ->with([
                'postedBy:id,name,email',
            ])
            ->orderByDesc('created_at')
            ->get();

        // Delivered/completed runs remain in Active until the driver payout is
        // released. Only released payouts belong in Recently completed.
        $activeStatuses = ['in_progress', 'accepted', 'collected', 'in_transit', 'pending', 'completion_pending', 'delivered', 'completed'];
        $completedStatuses = ['completed', 'delivered', 'closed'];

        $activeJobs = $assignedJobs
            ->filter(fn ($job) => in_array(strtolower((string) $job->status), $activeStatuses, true) && ! $job->payout_released_at)
            ->values();

        $completedJobs = $assignedJobs
            ->filter(fn ($job) => in_array(strtolower((string) $job->status), $completedStatuses, true) && (bool) $job->payout_released_at)
            ->sortByDesc('created_at')
            ->values();

        $applications = JobApplication::query()
            ->with([
                'job:id,title,price,platform_fee_amount,driver_payout_amount,status,posted_by_id,created_at',
                'job.postedBy:id,name,email',
            ])
            ->where('driver_id', $user->id)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        $stats = [
            'active_count' => $activeJobs->count(),
            'completed_count' => $completedJobs->count(),
            'pending_applications' => $applications->count(),
            'total_earnings' => $completedJobs->reduce(function ($carry, $job) {
                return $carry + $this->driverPayoutFor($job);
            }, 0.0),
        ];

        return response()->json([
            'stats' => $stats,
            'active' => $activeJobs,
            'applications' => $applications,
            'completed' => $completedJobs->take(20)->values(),
        ]);
    }

    protected function driverPayoutFor($job): float
    {
        $storedPayout = (float) ($job->driver_payout_amount ?? 0);

        if ($storedPayout > 0) {
            return $storedPayout;
        }

        $price = (float) ($job->price ?? 0);
        $storedFee = (float) ($job->platform_fee_amount ?? 0);
        $feePercent = (float) config('stripe.platform_fee_percent', 10);
        $platformFee = $storedFee > 0 ? $storedFee : round($price * ($feePercent / 100), 2);

        return max(round($price - $platformFee, 2), 0);
    }
}
