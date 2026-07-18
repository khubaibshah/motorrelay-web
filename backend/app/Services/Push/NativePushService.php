<?php

namespace App\Services\Push;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class NativePushService
{
    public function __construct(private readonly ApnsClient $apns) {}

    public function sendToUser(User $user, array $notification): void
    {
        // Protect against legacy duplicate rows while the registration
        // endpoint cleans up rotated device tokens.
        $subscriptions = $user->pushSubscriptions()->get()->unique('token');

        foreach ($subscriptions as $subscription) {
            try {
                if ($subscription->platform === 'ios') {
                    $this->apns->send($subscription, $this->apnsPayload($notification));
                }
            } catch (Throwable $exception) {
                Log::warning('Native push notification failed.', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'platform' => $subscription->platform,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    protected function apnsPayload(array $notification): array
    {
        return [
            'aps' => [
                'alert' => [
                    'title' => (string) ($notification['title'] ?? 'MotorRelay'),
                    'body' => (string) ($notification['body'] ?? 'You have a new update.'),
                ],
                'sound' => 'default',
                'badge' => 1,
            ],
            'type' => $notification['type'] ?? 'notification',
            'url' => $notification['url'] ?? '/notifications',
            'job_id' => $notification['job_id'] ?? null,
            'invoice_id' => $notification['invoice_id'] ?? null,
        ];
    }
}
