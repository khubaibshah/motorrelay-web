<?php

namespace App\Services\Payments;

use App\Models\Job;
use App\Models\User;
use App\Events\JobStatusChanged;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Transfer;
use Stripe\Webhook;

class StripePaymentService
{
    public function onboardDriver(User $user): string
    {
        $stripe = $this->client();
        if (! $user->stripe_account_id) {
            try {
                $account = $stripe->v2->core->accounts->create([
                    'contact_email' => $user->email, 'display_name' => $user->name ?: $user->email,
                    'identity' => ['country' => 'GB', 'entity_type' => 'individual', 'individual' => ['email' => $user->email]],
                    'configuration' => ['recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]]]],
                    'dashboard' => 'express', 'defaults' => ['responsibilities' => ['fees_collector' => 'application', 'losses_collector' => 'application']],
                    'metadata' => ['motorrelay_user_id' => (string) $user->id],
                ]);
            } catch (ApiErrorException $e) {
                abort(422, 'Stripe payout setup is not ready yet. In Stripe, make sure Connect and Accounts v2 are enabled for this sandbox.');
            }
            $user->forceFill(['stripe_account_id' => $account->id, 'stripe_onboarding_complete' => false, 'stripe_charges_enabled' => false, 'stripe_payouts_enabled' => false])->save();
        }
        $frontendUrl = rtrim(config('stripe.frontend_url'), '/');
        try {
            $link = $stripe->v2->core->accountLinks->create(['account' => $user->stripe_account_id, 'use_case' => ['type' => 'account_onboarding', 'account_onboarding' => ['configurations' => ['recipient'], 'refresh_url' => "{$frontendUrl}/profile?stripe=refresh", 'return_url' => "{$frontendUrl}/profile?stripe=return"]]]);
        } catch (ApiErrorException $e) {
            abort(422, 'Stripe could not create the payout onboarding link. Check that Stripe Connect Accounts v2 is enabled.');
        }
        return $link->url;
    }

    public function disconnectDriver(User $user): User
    {
        if ($user->stripe_account_id) {
            try { $this->client()->v2->core->accounts->close($user->stripe_account_id, ['applied_configurations' => ['recipient']]); }
            catch (ApiErrorException $e) { abort(422, 'Stripe could not disconnect this payout account. Try again or contact support.'); }
        }
        $user->forceFill(['stripe_account_id' => null, 'stripe_onboarding_complete' => false, 'stripe_charges_enabled' => false, 'stripe_payouts_enabled' => false])->save();
        return $user->fresh();
    }

    public function createCheckout(User $dealer, Job $job): array
    {
        if (in_array($job->payment_status, ['paid', 'payout_released'], true)) abort(422, 'This job has already been paid.');
        $this->configure();
        $amount = $this->toPence((float) $job->price);
        if ($amount <= 0) abort(422, 'Job price must be greater than zero before payment.');
        $fee = round((float) $job->price * ((float) config('stripe.platform_fee_percent') / 100), 2);
        $payout = max(round((float) $job->price - $fee, 2), 0);
        $frontend = rtrim(config('stripe.frontend_url'), '/');
        $session = Session::create(['mode' => 'payment', 'success_url' => "{$frontend}/jobs/{$job->id}?payment=success&session_id={CHECKOUT_SESSION_ID}", 'cancel_url' => "{$frontend}/jobs/{$job->id}?payment=cancelled", 'client_reference_id' => (string) $job->id, 'customer_email' => $dealer->email, 'metadata' => ['job_id' => (string) $job->id, 'dealer_id' => (string) $dealer->id, 'driver_id' => $job->assigned_to_id ? (string) $job->assigned_to_id : ''], 'line_items' => [['price_data' => ['currency' => config('stripe.currency'), 'unit_amount' => $amount, 'product_data' => ['name' => $job->title ?: "MotorRelay job #{$job->id}", 'description' => trim("{$job->pickup_postcode} to {$job->dropoff_postcode}")]], 'quantity' => 1]]]);
        $job->forceFill(['payment_status' => 'checkout_pending', 'stripe_checkout_session_id' => $session->id, 'platform_fee_amount' => $fee, 'driver_payout_amount' => $payout, 'platform_fee_reference' => sprintf('%s%% platform fee', config('stripe.platform_fee_percent'))])->save();
        return ['url' => $session->url, 'job' => $job->fresh()];
    }

    public function syncPayment(Job $job, ?string $sessionId): array
    {
        $sessionId = $sessionId ?: $job->stripe_checkout_session_id;
        if (! $sessionId) abort(422, 'No Stripe checkout session exists for this job.');
        $this->configure();
        try { $session = Session::retrieve($sessionId); }
        catch (ApiErrorException $e) { abort(422, 'Stripe could not check this payment yet. Try again in a moment.'); }
        $sessionJobId = $session->metadata->job_id ?? $session->client_reference_id ?? null;
        if ((string) $sessionJobId !== (string) $job->id) abort(422, 'This Stripe payment does not belong to this job.');
        if (($session->payment_status ?? null) === 'paid') $this->checkoutCompleted($session);
        return ['payment_status' => $session->payment_status ?? null, 'job' => $job->fresh()];
    }

    public function releasePayout(Job $job): array
    {
        if ($job->payment_status !== 'paid') abort(422, 'Dealer payment must be completed before releasing payout.');
        if (! $job->delivery_proof_path || $job->completion_status !== 'approved') abort(422, 'Dealer must approve the pre-delivery inspection before releasing payout.');
        if ($job->stripe_transfer_id) abort(422, 'Driver payout has already been released.');
        $driver = User::find($job->assigned_to_id);
        if (! $driver?->stripe_account_id || ! $this->driverCanReceiveTransfers($driver)) abort(422, 'Driver must finish Stripe payout setup before payout can be released.');
        $amount = $this->toPence((float) $job->driver_payout_amount);
        if ($amount <= 0) abort(422, 'Driver payout amount must be greater than zero.');
        $this->configure();
        $payload = ['amount' => $amount, 'currency' => config('stripe.currency'), 'destination' => $driver->stripe_account_id, 'metadata' => ['job_id' => (string) $job->id, 'driver_id' => (string) $driver->id]];
        if ($source = $this->sourceChargeId($job)) $payload['source_transaction'] = $source;
        try { $transfer = Transfer::create($payload); }
        catch (ApiErrorException $e) { abort(422, $e->getStripeCode() === 'balance_insufficient' ? 'Stripe says the platform balance is not available yet. Try again later.' : 'Stripe could not release this payout.'); }
        $job->forceFill(['payment_status' => 'payout_released', 'stripe_transfer_id' => $transfer->id, 'payout_released_at' => now()])->save();
        if ($driver) JobStatusChanged::dispatch($job->fresh(), 'driver_payout_released', [$driver->id]);
        return ['message' => 'Driver payout released.', 'job' => $job->fresh()];
    }

    public function handleWebhook(string $payload, ?string $signature): void
    {
        $this->configure();
        $secret = config('stripe.webhook_secret');
        try { $event = $secret ? Webhook::constructEvent($payload, $signature, $secret) : json_decode($payload, false, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { abort(400, 'Invalid Stripe webhook payload.'); }
        match ($event->type ?? null) { 'checkout.session.completed' => $this->checkoutCompleted($event->data->object), 'account.updated' => $this->accountUpdated($event->data->object), default => null };
    }

    private function checkoutCompleted(object $session): void
    {
        $jobId = $session->metadata->job_id ?? $session->client_reference_id ?? null; if (! $jobId) return;
        $job = Job::find($jobId); if (! $job) return;
        $job->forceFill(['payment_status' => 'paid', 'stripe_checkout_session_id' => $session->id ?? $job->stripe_checkout_session_id, 'stripe_payment_intent_id' => $session->payment_intent ?? $job->stripe_payment_intent_id, 'paid_at' => now()])->save();
        if ($job->assignedTo) JobStatusChanged::dispatch($job->fresh(), 'dealer_payment_received', [$job->assignedTo->id]);
    }

    private function accountUpdated(object $account): void
    {
        if (! isset($account->id)) return;
        User::where('stripe_account_id', $account->id)->update(['stripe_onboarding_complete' => (bool) ($account->details_submitted ?? false), 'stripe_charges_enabled' => (bool) ($account->charges_enabled ?? false), 'stripe_payouts_enabled' => (bool) ($account->payouts_enabled ?? false)]);
    }

    private function driverCanReceiveTransfers(User $driver): bool
    {
        try { $account = $this->client()->v2->core->accounts->retrieve($driver->stripe_account_id, ['include' => ['configuration.recipient', 'requirements']]); }
        catch (ApiErrorException $e) { return false; }
        $applied = (array) ($account->applied_configurations ?? []); $status = $account->configuration->recipient?->capabilities?->stripe_balance?->stripe_transfers?->status ?? null;
        $enabled = in_array((string) $status, ['active', 'enabled'], true);
        $driver->forceFill(['stripe_onboarding_complete' => in_array('recipient', $applied, true), 'stripe_payouts_enabled' => $enabled, 'stripe_charges_enabled' => false])->save();
        return $enabled;
    }

    private function sourceChargeId(Job $job): ?string
    {
        if (! $job->stripe_payment_intent_id) return null;
        try { $intent = PaymentIntent::retrieve(['id' => $job->stripe_payment_intent_id, 'expand' => ['latest_charge']]); }
        catch (ApiErrorException $e) { return null; }
        return is_string($intent->latest_charge ?? null) ? $intent->latest_charge : ($intent->latest_charge->id ?? null);
    }

    private function configure(): void
    { if (! config('stripe.secret_key')) abort(500, 'Stripe secret key is not configured.'); Stripe::setApiKey(config('stripe.secret_key')); }

    private function client(): StripeClient
    { if (! config('stripe.secret_key')) abort(500, 'Stripe secret key is not configured.'); return new StripeClient(config('stripe.secret_key')); }

    private function toPence(float $amount): int { return (int) round($amount * 100); }
}
