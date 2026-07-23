<?php

namespace App\Http\Controllers;

use App\Services\Payments\StripeIdentityService;
use App\Services\Payments\StripePaymentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Coordinates the single Stripe webhook endpoint for each Stripe domain. */
class StripeWebhookController extends Controller
{
    public function __construct(
        private StripePaymentService $payments,
        private StripeIdentityService $identity,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $payload = $request->getContent();
            $signature = $request->header('Stripe-Signature');
            $this->payments->handleWebhook($payload, $signature);
            $this->identity->handleWebhook($payload, $signature);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return response($exception->getMessage(), $exception->getStatusCode());
        }

        return response('ok');
    }
}
