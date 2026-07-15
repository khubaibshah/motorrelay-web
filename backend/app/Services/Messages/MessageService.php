<?php

namespace App\Services\Messages;

use App\Models\Job;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MessageService
{
    private const MESSAGEABLE_JOB_STATUSES = [
        'accepted',
        'in_progress',
        'collected',
        'in_transit',
        'delivered',
        'completion_pending',
    ];

    public function listThreadsFor(User $user): Collection
    {
        return MessageThread::query()
            ->with([
                'job:id,title,vehicle_make',
                'participants:id,name',
                'messages' => fn ($query) => $query->latest()->limit(1)->with(['receipts', 'attachments']),
            ])
            ->withCount(['messages as unread_count' => function ($query) use ($user) {
                $query->where('user_id', '<>', $user->id)
                    ->whereHas('receipts', function ($receipt) use ($user) {
                        $receipt->where('user_id', $user->id)->whereNull('viewed_at');
                    });
            }])
            ->whereHas('participants', fn ($query) => $query->where('user_id', $user->id))
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (MessageThread $thread) => $this->presentThread($thread));
    }

    public function listMessagesFor(User $user, MessageThread $thread): Collection
    {
        $this->assertThreadParticipant($thread, $user->id);

        $messages = $thread->messages()
            ->with(['user:id,name', 'attachments', 'receipts'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $message) => $this->presentMessage($message));

        $this->markDelivered($thread, $user->id);

        return $messages;
    }

    public function send(User $user, array $payload, array $attachments = []): array
    {
        $this->assertStarterPlanCanSend($user);

        [$thread, $message] = DB::transaction(function () use ($payload, $user, $attachments) {
            $thread = $this->resolveThread($user, $payload);

            $message = new Message([
                'body' => array_key_exists('body', $payload) ? $payload['body'] : null,
                'user_id' => $user->id,
                'meta' => [],
            ]);

            $thread->messages()->save($message);

            $this->storeAttachments($message, $attachments);
            $this->createReceipts($thread, $message, $user->id);

            $thread->touch();

            return [
                $thread->fresh([
                    'job:id,title,vehicle_make',
                    'participants:id,name',
                    'messages' => fn ($query) => $query->latest()->limit(1)->with('attachments'),
                ]),
                $message->fresh(['user:id,name', 'attachments', 'receipts']),
            ];
        });

        return [
            'thread' => $this->presentThread($thread, 0),
            'message' => $this->presentMessage($message),
        ];
    }

    public function markAsViewed(User $user, Message $message): array
    {
        $this->assertThreadParticipant($message->thread, $user->id);

        $receipt = $message->receipts()->firstOrCreate(
            ['user_id' => $user->id],
            ['delivered_at' => now()]
        );

        if (! $receipt->viewed_at) {
            $receipt->update(['viewed_at' => now()]);
        }

        return [
            'message_id' => $message->id,
            'viewed_at' => $receipt->viewed_at,
        ];
    }

    private function resolveThread(User $user, array $payload): MessageThread
    {
        if (! empty($payload['thread_id'])) {
            $thread = MessageThread::with('job')->findOrFail($payload['thread_id']);

            $this->assertThreadParticipant($thread, $user->id);
            $this->assertJobIsMessageable($thread->job, $user->id);

            return $thread;
        }

        $job = null;
        if (! empty($payload['job_id'])) {
            $job = Job::findOrFail($payload['job_id']);
            $this->assertJobIsMessageable($job, $user->id, $payload['recipient_id']);
        }

        $thread = MessageThread::create([
            'subject' => $payload['subject'],
            'job_id' => $job?->id,
        ]);

        $thread->participants()->attach([$user->id, $payload['recipient_id']]);

        return $thread;
    }

    private function assertStarterPlanCanSend(User $user): void
    {
        $planSlug = $user->plan_slug ?? Str::slug((string) $user->plan, '_');

        if ($planSlug !== 'starter' || $user->isAdmin()) {
            return;
        }

        $cooldownHours = config('jobs.plan_limits.starter.message_cooldown_hours', 24);
        if ($cooldownHours <= 0) {
            return;
        }

        $recentMessage = Message::where('user_id', $user->id)->latest()->first();
        if ($recentMessage && $recentMessage->created_at->gt(Carbon::now()->subHours($cooldownHours))) {
            abort(429, sprintf(
                'Starter plan allows one message every %d hours. Upgrade for instant replies.',
                $cooldownHours
            ));
        }
    }

    private function assertThreadParticipant(MessageThread $thread, int $userId): void
    {
        if (! $thread->participants()->where('user_id', $userId)->exists()) {
            abort(403, 'You do not belong to this thread.');
        }
    }

    private function assertJobIsMessageable(?Job $job, int $senderId, ?int $recipientId = null): void
    {
        if (! $job) {
            return;
        }

        $status = strtolower((string) $job->status);
        if (! $job->assigned_to_id || ! in_array($status, self::MESSAGEABLE_JOB_STATUSES, true)) {
            abort(422, 'Messaging is available once a dealer accepts a driver.');
        }

        $allowed = collect([$job->posted_by_id, $job->assigned_to_id]);
        if (! $allowed->contains($senderId)) {
            abort(403, 'You are not allowed to message on this job.');
        }

        if ($recipientId !== null && ! $allowed->contains($recipientId)) {
            abort(403, 'Recipient must be part of the job assignment.');
        }
    }

    private function storeAttachments(Message $message, array $files): void
    {
        foreach ($files as $file) {
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

    private function createReceipts(MessageThread $thread, Message $message, int $senderId): void
    {
        $now = now();
        $participantIds = $thread->participants()->pluck('user_id');

        foreach ($participantIds as $participantId) {
            $message->receipts()->firstOrCreate(
                ['user_id' => $participantId],
                [
                    'delivered_at' => $now,
                    'viewed_at' => $participantId === $senderId ? $now : null,
                ]
            );
        }
    }

    private function markDelivered(MessageThread $thread, int $userId): void
    {
        DB::table('message_receipts')
            ->whereIn('message_id', $thread->messages()->pluck('id'))
            ->where('user_id', $userId)
            ->whereNull('delivered_at')
            ->update([
                'delivered_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function presentThread(MessageThread $thread, ?int $unreadCount = null): array
    {
        return [
            'id' => $thread->id,
            'subject' => $this->threadSubject($thread),
            'job_id' => $thread->job_id,
            'job' => $this->threadJobPayload($thread),
            'participants' => $thread->participants->map(fn (User $participant) => [
                'id' => $participant->id,
                'name' => $participant->name,
            ]),
            'last_message' => $this->lastMessageSummary($thread),
            'updated_at' => $thread->updated_at,
            'unread_count' => $unreadCount ?? $thread->unread_count,
        ];
    }

    private function presentMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'meta' => $message->meta,
            'user' => [
                'id' => $message->user->id,
                'name' => $message->user->name,
            ],
            'attachments' => $message->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'url' => Storage::disk($attachment->disk)->url($attachment->path),
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
            ]),
            'receipts' => $message->receipts->map(fn ($receipt) => [
                'user_id' => $receipt->user_id,
                'delivered_at' => $receipt->delivered_at,
                'viewed_at' => $receipt->viewed_at,
            ]),
            'created_at' => $message->created_at,
        ];
    }

    private function threadSubject(MessageThread $thread): string
    {
        if ($thread->job) {
            return $thread->job->title ?: sprintf('Run #%d', $thread->job->id);
        }

        return $thread->subject ?: 'Conversation';
    }

    private function threadJobPayload(MessageThread $thread): ?array
    {
        if (! $thread->job) {
            return null;
        }

        return [
            'id' => $thread->job->id,
            'title' => $thread->job->title,
            'vehicle_make' => $thread->job->vehicle_make,
        ];
    }

    private function lastMessageSummary(MessageThread $thread): ?string
    {
        $lastMessage = $thread->messages->first();
        $lastSummary = $lastMessage?->body;

        if (! $lastSummary && $lastMessage) {
            $metaType = $lastMessage->meta['type'] ?? null;
            if ($metaType === 'location_update') {
                return 'Live location update';
            }

            if ($lastMessage->attachments->isNotEmpty()) {
                return '[Attachment]';
            }
        }

        return $lastSummary;
    }
}
