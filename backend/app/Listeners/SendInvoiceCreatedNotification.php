<?php

namespace App\Listeners;

use App\Events\InvoiceCreated;
use App\Notifications\InvoiceReadyNotification;
use Illuminate\Support\Facades\Notification;

class SendInvoiceCreatedNotification
{
    public function handle(InvoiceCreated $event): void
    {
        $invoice = $event->invoice->loadMissing(['job.postedBy', 'job.assignedTo']);
        $recipients = collect([$invoice->job?->postedBy, $invoice->job?->assignedTo])->filter()->unique('id');
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new InvoiceReadyNotification($invoice));
        }
    }
}
