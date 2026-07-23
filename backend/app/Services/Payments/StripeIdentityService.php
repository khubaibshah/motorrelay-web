<?php

namespace App\Services\Payments;

use App\Models\User;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Webhook;

/** Owns Stripe Identity sessions and their webhook-driven verification state. */
class StripeIdentityService
{
    public function createSession(User $user): array
    {
        $this->configure();

        try {
            $session = $this->client()->identity->verificationSessions->create([
                'type' => 'document',
                'client_reference_id' => (string) $user->id,
                'provided_details' => ['email' => $user->email],
                'metadata' => ['motorrelay_user_id' => (string) $user->id],
                'options' => ['document' => ['require_live_capture' => true, 'require_matching_selfie' => true]],
                'return_url' => rtrim(config('stripe.identity_return_url'), '/'),
            ]);
        } catch (ApiErrorException $e) {
            report($e);
            abort(422, 'Stripe could not start identity verification. Check that Stripe Identity is enabled.');
        }

        $user->forceFill([
            'stripe_identity_verification_session_id' => $session->id,
            'stripe_identity_status' => $session->status ?: 'requires_input',
            'stripe_identity_verified_at' => null,
        ])->save();

        return ['session_id' => $session->id, 'status' => $session->status, 'url' => $session->url];
    }

    /** Handles identity events sent to the existing Stripe webhook endpoint. */
    public function handleWebhook(string $payload, ?string $signature): void
    {
        $this->configure();
        $secret = config('stripe.webhook_secret');

        try {
            $event = $secret ? Webhook::constructEvent($payload, $signature, $secret) : json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            // StripePaymentService already validates the shared webhook payload.
            return;
        }

        if (! str_starts_with((string) ($event->type ?? ''), 'identity.verification_session.')) return;
        $session = $event->data->object ?? null;
        $userId = $session?->metadata?->motorrelay_user_id ?? $session?->client_reference_id ?? null;
        $user = $userId ? User::find($userId) : null;
        if (! $session || ! $user) return;

        $status = match ($event->type) {
            'identity.verification_session.verified' => 'verified',
            'identity.verification_session.processing' => 'processing',
            'identity.verification_session.requires_input' => 'requires_input',
            'identity.verification_session.canceled' => 'canceled',
            default => null,
        };
        if (! $status) return;

        $user->forceFill([
            'stripe_identity_verification_session_id' => $session->id ?? $user->stripe_identity_verification_session_id,
            'stripe_identity_status' => $status,
            'stripe_identity_verified_at' => $status === 'verified' ? now() : null,
        ])->save();
    }

    private function configure(): void
    {
        if (! config('stripe.secret_key')) abort(500, 'Stripe secret key is not configured.');
        Stripe::setApiKey(config('stripe.secret_key'));
    }

    private function client(): StripeClient
    {
        return new StripeClient(config('stripe.secret_key'));
    }
}
