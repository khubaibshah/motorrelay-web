<?php

namespace App\Listeners;

use App\Events\JobStatusChanged;
use App\Models\User;
use App\Notifications\JobStatusNotification;
use Illuminate\Support\Facades\Notification;

class SendJobStatusNotification
{
    public function handle(JobStatusChanged $event): void
    {
        $recipients = User::query()->whereIn('id', $event->recipientIds)->get();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new JobStatusNotification($event->job, $event->event, $event->meta));
        }
    }
}
