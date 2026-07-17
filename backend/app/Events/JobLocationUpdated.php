<?php

namespace App\Events;

use App\Models\Job;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Job $job,
        public array $location
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->job->posted_by_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'job.location.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'job_id' => $this->job->id,
            'job_title' => $this->job->title,
            'location' => $this->location,
            'last_tracked_at' => $this->job->last_tracked_at?->toIso8601String(),
        ];
    }
}
