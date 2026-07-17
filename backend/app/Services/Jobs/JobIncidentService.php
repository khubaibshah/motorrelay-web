<?php

namespace App\Services\Jobs;

use App\Models\Job;
use App\Models\JobIncident;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use App\Events\JobStatusChanged;
use Illuminate\Http\UploadedFile;

class JobIncidentService
{
    /**
     * @param  array<int, UploadedFile>  $attachments
     */
    public function report(Job $job, User $reporter, array $payload, array $attachments = []): JobIncident
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

        $this->createIncidentMessage($job, $reporter, $incident, $attachments);
        $this->notifyStakeholders($job->fresh(['postedBy:id,name', 'assignedTo:id,name']), $reporter, $incident);

        return $incident->fresh(['reportedBy:id,name']);
    }

    public function markRecoverySent(Job $job, JobIncident $incident, User $dealer): JobIncident
    {
        $this->assertCanSendRecovery($job, $dealer);

        if (! $incident->recovery_required) {
            abort(422, 'Recovery was not requested for this issue.');
        }

        if ($incident->recovery_sent_at) {
            return $incident->fresh(['reportedBy:id,name', 'recoverySentBy:id,name', 'recoveryCompletedBy:id,name']);
        }

        $incident->forceFill([
            'status' => 'recovery_sent',
            'recovery_sent_by_id' => $dealer->id,
            'recovery_sent_at' => now(),
        ])->save();

        $this->createRecoverySentMessage($job, $dealer, $incident);
        $this->notifyDriverRecoverySent($job->fresh(['postedBy:id,name', 'assignedTo:id,name']), $dealer, $incident);

        return $incident->fresh(['reportedBy:id,name', 'recoverySentBy:id,name', 'recoveryCompletedBy:id,name']);
    }

    public function markRecoveryCompleted(Job $job, JobIncident $incident, User $driver): JobIncident
    {
        $this->assertCanCompleteRecovery($job, $driver);

        if (! $incident->recovery_sent_at) {
            abort(422, 'Recovery must be sent before the driver can confirm it happened.');
        }

        if ($incident->recovery_completed_at) {
            return $incident->fresh(['reportedBy:id,name', 'recoverySentBy:id,name', 'recoveryCompletedBy:id,name']);
        }

        $incident->forceFill([
            'status' => 'recovery_completed',
            'recovery_completed_by_id' => $driver->id,
            'recovery_completed_at' => now(),
            'resolved_at' => now(),
        ])->save();

        $this->createRecoveryCompletedMessage($job, $driver, $incident);
        $this->notifyDealerRecoveryCompleted($job->fresh(['postedBy:id,name', 'assignedTo:id,name']), $driver, $incident);

        return $incident->fresh(['reportedBy:id,name', 'recoverySentBy:id,name', 'recoveryCompletedBy:id,name']);
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

    private function assertCanSendRecovery(Job $job, User $dealer): void
    {
        if ($dealer->isAdmin()) {
            return;
        }

        if ((int) $job->posted_by_id !== (int) $dealer->id) {
            abort(403, 'Only the dealer can mark recovery as sent for this run.');
        }
    }

    private function assertCanCompleteRecovery(Job $job, User $driver): void
    {
        if ($driver->isAdmin()) {
            return;
        }

        if ((int) $job->assigned_to_id !== (int) $driver->id) {
            abort(403, 'Only the assigned driver can confirm recovery happened.');
        }
    }

    /**
     * @param  array<int, UploadedFile>  $attachments
     */
    private function createIncidentMessage(Job $job, User $reporter, JobIncident $incident, array $attachments = []): void
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

        $this->storeMessageAttachments($message, $attachments);
        $this->createReceipts($thread, $message, $reporter->id);
        $thread->touch();
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function storeMessageAttachments(Message $message, array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->store('message-attachments', 'public');

            $message->attachments()->create([
                'disk' => 'public',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }

    private function createRecoverySentMessage(Job $job, User $dealer, JobIncident $incident): void
    {
        $thread = $this->resolveThread($job, $dealer);

        $message = $thread->messages()->create([
            'user_id' => $dealer->id,
            'body' => 'Recovery sent by dealer. Please wait somewhere safe and keep the dealer updated if your location changes.',
            'meta' => [
                'type' => 'job_recovery_sent',
                'incident_id' => $incident->id,
            ],
        ]);

        $this->createReceipts($thread, $message, $dealer->id);
        $thread->touch();
    }

    private function createRecoveryCompletedMessage(Job $job, User $driver, JobIncident $incident): void
    {
        $thread = $this->resolveThread($job, $driver);

        $message = $thread->messages()->create([
            'user_id' => $driver->id,
            'body' => 'Recovery completed. The driver has confirmed the recovery has happened.',
            'meta' => [
                'type' => 'job_recovery_completed',
                'incident_id' => $incident->id,
            ],
        ]);

        $this->createReceipts($thread, $message, $driver->id);
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

        JobStatusChanged::dispatch($job, 'job_incident_reported', $recipients->pluck('id')->all(), [
            'incident_id' => $incident->id,
            'incident_type' => $incident->type,
            'recovery_required' => $incident->recovery_required,
            'reported_by' => [
                'id' => $reporter->id,
                'name' => $reporter->name,
            ],
        ]);
    }

    private function notifyDriverRecoverySent(Job $job, User $dealer, JobIncident $incident): void
    {
        if (! $job->assignedTo) {
            return;
        }

        JobStatusChanged::dispatch($job, 'job_recovery_sent', [$job->assignedTo->id], [
            'incident_id' => $incident->id,
            'sent_by' => [
                'id' => $dealer->id,
                'name' => $dealer->name,
            ],
        ]);
    }

    private function notifyDealerRecoveryCompleted(Job $job, User $driver, JobIncident $incident): void
    {
        if (! $job->postedBy) {
            return;
        }

        JobStatusChanged::dispatch($job, 'job_recovery_completed', [$job->postedBy->id], [
            'incident_id' => $incident->id,
            'confirmed_by' => [
                'id' => $driver->id,
                'name' => $driver->name,
            ],
        ]);
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
