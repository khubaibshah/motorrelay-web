<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Services\Payments\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripePaymentController extends Controller
{
    public function __construct(protected StripePaymentService $payments)
    {
    }

    public function onboardDriver(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isDriver()) abort(403, 'Only drivers can set up payouts.');
        return response()->json(['url' => $this->payments->onboardDriver($user)]);
    }

    public function disconnectDriver(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isDriver()) abort(403, 'Only drivers can disconnect payout accounts.');
        return response()->json(['message' => 'Payout account disconnected.', 'user' => $this->payments->disconnectDriver($user)]);
    }

    public function createJobCheckout(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        $this->authorizeDealer($user, $job, 'Only the dealer that posted this job can take payment.');
        return response()->json($this->payments->createCheckout($user, $job));
    }

    public function syncJobPayment(Request $request, Job $job): JsonResponse
    {
        $this->authorizeDealer($request->user(), $job, 'Only the dealer that posted this job can check payment.');
        return response()->json($this->payments->syncPayment($job, $request->string('session_id')->toString() ?: null));
    }

    public function releaseDriverPayout(Request $request, Job $job): JsonResponse
    {
        $this->authorizeDealer($request->user(), $job, 'Only the dealer that posted this job can release payout.');
        return response()->json($this->payments->releasePayout($job));
    }

    private function authorizeDealer($user, Job $job, string $message): void
    {
        if (! $user || (! $user->isAdmin() && $job->posted_by_id !== $user->id)) abort(403, $message);
    }
}
