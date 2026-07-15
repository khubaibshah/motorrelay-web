<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class SafeBroadcastChannel extends BroadcastChannel
{
    public function send($notifiable, Notification $notification): mixed
    {
        try {
            return parent::send($notifiable, $notification);
        } catch (Throwable $exception) {
            Log::warning('Realtime notification broadcast failed.', [
                'notification' => $notification::class,
                'notifiable_type' => $notifiable::class,
                'notifiable_id' => $notifiable->getKey(),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
