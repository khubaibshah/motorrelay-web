<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class JobStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Job $job,
        protected string $event,
        protected ?array $meta = null
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $job = $this->job->fresh(['postedBy:id,name', 'assignedTo:id,name']);
        $details = $this->detailsForEvent($job);

        return [
            'type' => 'job.event',
            'event' => $this->event,
            'title' => $details['title'],
            'body' => $details['body'],
            'action_label' => $details['action_label'],
            'url' => $details['url'],
            'job_id' => $job->id,
            'job_status' => $job->status,
            'job_title' => $job->title,
            'assigned_driver' => $job->assignedTo ? [
                'id' => $job->assignedTo->id,
                'name' => $job->assignedTo->name,
            ] : null,
            'meta' => $this->meta,
        ];
    }

    protected function detailsForEvent(Job $job): array
    {
        $jobLabel = $job->title ?: sprintf('Job #%d', $job->id);
        $route = sprintf('/jobs/%d', $job->id);

        return match ($this->event) {
            'driver_applied' => [
                'title' => 'New driver request',
                'body' => sprintf('A driver applied for %s.', $jobLabel),
                'action_label' => 'Review request',
                'url' => $route,
            ],
            'application_accepted' => [
                'title' => 'Job request accepted',
                'body' => sprintf('Your request was accepted for %s.', $jobLabel),
                'action_label' => 'Open job',
                'url' => $route,
            ],
            'application_declined' => [
                'title' => 'Job request declined',
                'body' => sprintf('Your request was declined for %s.', $jobLabel),
                'action_label' => 'Open jobs',
                'url' => '/jobs',
            ],
            'dealer_payment_received' => [
                'title' => 'Dealer payment held',
                'body' => sprintf('Dealer payment has been captured for %s.', $jobLabel),
                'action_label' => 'Open job',
                'url' => $route,
            ],
            'dealer_updated_job' => [
                'title' => 'Job updated',
                'body' => sprintf('The dealer updated %s.', $jobLabel),
                'action_label' => 'Open job',
                'url' => $route,
            ],
            'driver_marked_collected' => [
                'title' => 'Vehicle collected',
                'body' => sprintf('The driver marked %s as collected.', $jobLabel),
                'action_label' => 'Open job',
                'url' => $route,
            ],
            'driver_marked_delivered' => [
                'title' => 'Vehicle delivered',
                'body' => sprintf('The driver marked %s as delivered.', $jobLabel),
                'action_label' => 'Open job',
                'url' => $route,
            ],
            'driver_submitted_completion' => [
                'title' => 'Inspection uploaded',
                'body' => sprintf('Pre-delivery inspection photos were uploaded for %s.', $jobLabel),
                'action_label' => 'Review inspection',
                'url' => $route,
            ],
            'completion_approved' => [
                'title' => 'Completion approved',
                'body' => sprintf('Completion was approved for %s.', $jobLabel),
                'action_label' => 'Open job',
                'url' => $route,
            ],
            'completion_rejected' => [
                'title' => 'Completion rejected',
                'body' => sprintf('Completion was rejected for %s.', $jobLabel),
                'action_label' => 'Open job',
                'url' => $route,
            ],
            'driver_cancelled_job' => [
                'title' => 'Driver cancelled job',
                'body' => sprintf('The driver cancelled %s.', $jobLabel),
                'action_label' => 'Open job',
                'url' => $route,
            ],
            'dealer_cancelled_job' => [
                'title' => 'Dealer cancelled job',
                'body' => sprintf('The dealer cancelled %s.', $jobLabel),
                'action_label' => 'Open job',
                'url' => $route,
            ],
            'driver_payout_released' => [
                'title' => 'Driver payout released',
                'body' => sprintf('Payout was released for %s.', $jobLabel),
                'action_label' => 'Open job',
                'url' => $route,
            ],
            default => [
                'title' => 'Job update',
                'body' => sprintf('There was an update on %s.', $jobLabel),
                'action_label' => 'Open job',
                'url' => $route,
            ],
        };
    }
}
