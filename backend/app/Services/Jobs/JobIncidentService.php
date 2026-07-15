<?php

namespace App\Services\Jobs;

use App\Models\Job;
use App\Models\JobIncident;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use App\Notifications\JobStatusNotification;
use Illuminate\Support\Facades\Notification;

class JobIncidentService
{
    public function report(Job $job, User $reporter, array $payload): JobIncident
    {
        $this->assertCanReport($job, $reporter);

        $incident = $job->incidents()->create([
            'reported_by_id' => $reporter->id,
            'type' => $payload['type'],
            'recovery_required' => (bool) ($payload['recovery_required'] ?? false),
            'vehicle_safe' => array_key_exists('vehicle_safe', $payload) ? (bool) $payload['vehicle_safe'] : null,
            'blocking_road' => array_key_exists('blocking_road', $payload) ? (bool) $payload['blocking_road'] : null,
            'location_label' => $payload['location_label'] ?? null,
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'description' => $payload['description'] ?? null,
        ]);

        $this->createIncidentMessage($job, $reporter, $incident);
        $this->notifyStakeholders($job->fresh(['postedBy:id,name', 'assignedTo:id,name']), $reporter, $incident);

        return $incident->fresh(['reportedBy:id,name']);
    }

    private function assertCanReport(Job $job, User $reporter): void
    {
        if ($reporter->isAdmin()) {
            return;
        }

        if ((int) $job->assigned_to_id !== (int) $reporter->id) {
            abort(403, 'Only the assigned driver can report an issue on this run.');
        }

        $activeStatuses = ['accepted', 'in_progress', 'collected', 'in_transit'];
        if (! in_array(strtolower((string) $job->status), $activeStatuses, true)) {
            abort(422, 'Issues can only be reported while the run is active.');
        }
    }

    private function createIncidentMessage(Job $job, User $reporter, JobIncident $incident): void
    {
        $thread = $this->resolveThread($job, $reporter);

        $message = $thread->messages()->create([
            'user_id' => $reporter->id,
            'body' => $this->messageBody($incident),
            'meta' => [
                'type' => 'job_incident',
                'incident_id' => $incident->id,
                'incident_type' => $incident->type,
                'recovery_required' => $incident->recovery_required,
            ],
        ]);

        $this->createReceipts($thread, $message, $reporter->id);
        $thread->touch();
    }

    private function resolveThread(Job $job, User $reporter): MessageThread
    {
        $participantIds = collect([$job->posted_by_id, $job->assigned_to_id ?: $reporter->id])
            ->filter()
            ->unique()
            ->values();

        $thread = MessageThread::query()
            ->where('job_id', $job->id)
            ->whereHas('participants', fn ($query) => $query->where('user_id', $participantIds[0]))
            ->when($participantIds->count() > 1, fn ($query) => $query->whereHas('participants', fn ($participant) => $participant->where('user_id', $participantIds[1])))
            ->first();

        if ($thread) {
            return $thread;
        }

        $thread = MessageThread::create([
            'job_id' => $job->id,
            'subject' => $job->title ?: sprintf('Run #%d', $job->id),
        ]);

        $thread->participants()->attach($participantIds->all());

        return $thread;
    }

    private function createReceipts(MessageThread $thread, Message $message, int $senderId): void
    {
        $now = now();

        foreach ($thread->participants()->pluck('user_id') as $participantId) {
            $message->receipts()->create([
                'user_id' => $participantId,
                'delivered_at' => $now,
                'viewed_at' => $participantId === $senderId ? $now : null,
            ]);
        }
    }

    private function notifyStakeholders(Job $job, User $reporter, JobIncident $incident): void
    {
        $recipients = collect([$job->postedBy])
            ->merge(User::query()->where('role', 'admin')->get())
            ->filter()
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new JobStatusNotification($job, 'job_incident_reported', [
            'incident_id' => $incident->id,
            'incident_type' => $incident->type,
            'recovery_required' => $incident->recovery_required,
            'reported_by' => [
                'id' => $reporter->id,
                'name' => $reporter->name,
            ],
        ]));
    }

    private function messageBody(JobIncident $incident): string
    {
        $type = str_replace('_', ' ', $incident->type);
        $lines = [
            'Issue reported: '.ucfirst($type),
            $incident->recovery_required ? 'Recovery requested: yes' : 'Recovery requested: no',
        ];

        if ($incident->location_label) {
            $lines[] = 'Location: '.$incident->location_label;
        }

        if ($incident->description) {
            $lines[] = 'Details: '.$incident->description;
        }

        return implode("\n", $lines);
    }
}
