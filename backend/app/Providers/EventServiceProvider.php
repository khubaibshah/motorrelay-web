<?php

namespace App\Providers;

use App\Events\InvoiceCreated;
use App\Events\ExpenseReviewed;
use App\Events\JobStatusChanged;
use App\Listeners\SendExpenseReviewedNotification;
use App\Listeners\SendInvoiceCreatedNotification;
use App\Listeners\SendJobStatusNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        JobStatusChanged::class => [SendJobStatusNotification::class],
        InvoiceCreated::class => [SendInvoiceCreatedNotification::class],
        ExpenseReviewed::class => [SendExpenseReviewedNotification::class],
    ];

    public function boot(): void
    {
        //
    }

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
