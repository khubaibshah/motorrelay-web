<?php

namespace App\Listeners;

use App\Events\ExpenseReviewed;
use App\Notifications\ExpenseReviewedNotification;
use Illuminate\Support\Facades\Notification;

class SendExpenseReviewedNotification
{
    public function handle(ExpenseReviewed $event): void
    {
        $expense = $event->expense->loadMissing('driver');
        if ($expense->driver) Notification::send($expense->driver, new ExpenseReviewedNotification($expense));
    }
}
