<?php

namespace App\Http\Controllers;

use App\Services\Payments\StripeIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Handles user-facing Stripe Identity verification actions. */
class StripeIdentityController extends Controller
{
    public function __construct(private StripeIdentityService $identity)
    {
    }

    public function createSession(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->isAdmin()) {
            abort(403, 'This account does not require identity verification.');
        }

        return response()->json($this->identity->createSession($user));
    }
}
