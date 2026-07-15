<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Push\NativePushService;
use Illuminate\Notifications\Notification;

class NativePushChannel
{
    public function __construct(private readonly NativePushService $push) {}

    public function send(User $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toArray')) {
            return;
        }

        $this->push->sendToUser($notifiable, $notification->toArray($notifiable));
    }
}
