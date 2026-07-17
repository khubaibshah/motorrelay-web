<?php

namespace App\Providers;

use App\Events\InvoiceCreated;
use App\Events\JobStatusChanged;
use App\Listeners\SendInvoiceCreatedNotification;
use App\Listeners\SendJobStatusNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        JobStatusChanged::class => [SendJobStatusNotification::class],
        InvoiceCreated::class => [SendInvoiceCreatedNotification::class],
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
