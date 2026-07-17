<?php

namespace App\Events;

use App\Models\Job;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Job $job,
        public string $event,
        public array $recipientIds = [],
        public ?array $meta = null,
    ) {}
}
