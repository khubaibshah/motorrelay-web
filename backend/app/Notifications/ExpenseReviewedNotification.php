<?php

namespace App\Notifications;

use App\Models\Expense;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class ExpenseReviewedNotification extends Notification
{
    use Queueable;

    public function __construct(protected Expense $expense)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
    }

    protected function payload(): array
    {
        return [
            'type' => 'expense.reviewed',
            'title' => 'Expense reviewed',
            'body' => sprintf('Your expense for job #%d was reviewed.', $this->expense->job_id),
            'action_label' => 'Open job',
            'url' => url('/jobs/' . $this->expense->job_id),
            'expense_id' => $this->expense->id,
            'job_id' => $this->expense->job_id,
            'status' => $this->expense->status,
            'amount' => (float) $this->expense->amount,
            'vat_rate' => (float) $this->expense->vat_rate,
            'total' => $this->expense->total_amount,
            'review_note' => $this->expense->review_note,
            'reviewed_at' => optional($this->expense->reviewed_at)->toIso8601String(),
        ];
    }
}
