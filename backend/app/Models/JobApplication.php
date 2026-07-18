<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplication extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
        self::STATUS_WITHDRAWN,
    ];

    protected $fillable = [
        'job_id',
        'driver_id',
        'status',
        'message',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
