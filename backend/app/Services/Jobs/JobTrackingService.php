<?php

namespace App\Services\Jobs;

use App\Events\JobLocationUpdated;
use App\Events\JobStatusChanged;
use App\Models\Job;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class JobTrackingService
{
    public const ACTIVE_TRACKING_STATUSES = ['accepted', 'collected', 'in_transit', 'in_progress'];

    public function storeLocation(Job $job, User $driver, array $validated): array
    {
        [$messagePayload, $jobPayload, $locationPayload] = DB::transaction(function () use ($validated, $job, $driver) {
            $job->update([
                'current_latitude' => $validated['latitude'],
                'current_longitude' => $validated['longitude'],
                'last_tracked_at' => now(),
            ]);

            $thread = $this->resolveThread($job);
            $thread->touch();

            $meta = [
                'type' => 'location_update',
                'location' => [
                    'lat' => (float) $validated['latitude'],
                    'lng' => (float) $validated['longitude'],
                    'speed_kph' => isset($validated['speed_kph']) ? (float) $validated['speed_kph'] : null,
                    'heading' => isset($validated['heading']) ? (float) $validated['heading'] : null,
                    'accuracy' => isset($validated['accuracy']) ? (float) $validated['accuracy'] : null,
                    'recorded_at' => now()->toIso8601String(),
                    'source' => $validated['source'] ?? null,
                ],
                'eta_minutes' => $validated['eta_minutes'] ?? null,
                'job' => [
                    'id' => $job->id,
                    'title' => $job->title,
                ],
                'destination' => [
                    'label' => $job->dropoff_label,
                    'postcode' => $job->dropoff_postcode,
                ],
                'driver' => [
                    'id' => $driver->id,
                    'name' => $driver->name,
                ],
            ];

            $message = $thread->messages()->create([
                'user_id' => $driver->id,
                'body' => null,
                'meta' => $meta,
            ]);

            $this->createReceipts($thread, $message, $driver->id);

            $message->load(['user:id,name', 'attachments', 'receipts']);

            return [
                $this->messagePayload($message),
                [
                    'id' => $job->id,
                    'current_latitude' => $job->current_latitude,
                    'current_longitude' => $job->current_longitude,
                    'last_tracked_at' => $job->last_tracked_at,
                ],
                $meta['location'],
            ];
        });

        broadcast(new JobLocationUpdated($job->fresh(), $locationPayload));

        return [
            'message' => $messagePayload,
            'job' => $jobPayload,
        ];
    }

    public function requestLocationUpdate(Job $job, User $dealer): void
    {
        $thread = $this->resolveThread($job);

        $message = $thread->messages()->create([
            'user_id' => $dealer->id,
            'body' => 'The dealer requested a live location update.',
            'meta' => [
                'type' => 'location_request',
                'requested_by' => [
                    'id' => $dealer->id,
                    'name' => $dealer->name,
                ],
            ],
        ]);

        $this->createReceipts($thread, $message, $dealer->id);
        $thread->touch();

        if ($job->assignedTo) {
            JobStatusChanged::dispatch($job->fresh(['postedBy:id,name', 'assignedTo:id,name']), 'dealer_requested_location', [$job->assignedTo->id]);
        }
    }

    public function ensureTrackingCanBeShared(Job $job): void
    {
        if (!$job->assigned_to_id) {
            abort(422, 'Tracking is only available once a driver is assigned.');
        }

        if (!$this->isActiveForTracking($job)) {
            abort(422, 'Tracking is available once the job is underway.');
        }
    }

    public function ensureTrackingCanBeRequested(Job $job): void
    {
        if (!$job->assigned_to_id || !$job->assignedTo) {
            abort(422, 'Location can only be requested once a driver is assigned.');
        }

        if (!$this->isActiveForTracking($job)) {
            abort(422, 'Location updates can only be requested while the run is active.');
        }
    }

    protected function isActiveForTracking(Job $job): bool
    {
        return in_array(strtolower((string) $job->status), self::ACTIVE_TRACKING_STATUSES, true);
    }

    protected function resolveThread(Job $job): MessageThread
    {
        $query = MessageThread::query()
            ->where('job_id', $job->id)
            ->whereHas('participants', fn ($participantQuery) => $participantQuery->where('user_id', $job->posted_by_id))
            ->whereHas('participants', fn ($participantQuery) => $participantQuery->where('user_id', $job->assigned_to_id))
            ->with('participants');

        $thread = $query->first();

        if ($thread) {
            return $thread;
        }

        $thread = MessageThread::create([
            'subject' => $job->title ?: sprintf('Run #%d updates', $job->id),
            'job_id' => $job->id,
        ]);

        $participantIds = collect([$job->posted_by_id, $job->assigned_to_id])->filter()->unique()->values();
        if ($participantIds->isNotEmpty()) {
            $thread->participants()->syncWithoutDetaching($participantIds);
        }

        return $thread->fresh('participants');
    }

    protected function createReceipts(MessageThread $thread, Message $message, int $senderId): void
    {
        $now = now();
        $payload = $thread->participants()
            ->pluck('user_id')
            ->map(fn ($participantId) => [
                'user_id' => $participantId,
                'delivered_at' => $now,
                'viewed_at' => $participantId === $senderId ? $now : null,
            ])
            ->all();

        $message->receipts()->createMany($payload);
    }

    protected function messagePayload(Message $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'meta' => $message->meta,
            'user' => [
                'id' => $message->user->id,
                'name' => $message->user->name,
            ],
            'attachments' => [],
            'receipts' => $message->receipts->map(fn ($receipt) => [
                'user_id' => $receipt->user_id,
                'delivered_at' => $receipt->delivered_at,
                'viewed_at' => $receipt->viewed_at,
            ]),
            'created_at' => $message->created_at,
        ];
    }
}
