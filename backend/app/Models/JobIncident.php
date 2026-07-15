<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'reported_by_id',
        'recovery_sent_by_id',
        'recovery_completed_by_id',
        'type',
        'status',
        'recovery_required',
        'recovery_sent_at',
        'recovery_completed_at',
        'vehicle_safe',
        'blocking_road',
        'location_label',
        'latitude',
        'longitude',
        'description',
        'resolved_at',
    ];

    protected $casts = [
        'recovery_required' => 'boolean',
        'vehicle_safe' => 'boolean',
        'blocking_road' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'recovery_sent_at' => 'datetime',
        'recovery_completed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }

    public function recoverySentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recovery_sent_by_id');
    }

    public function recoveryCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recovery_completed_by_id');
    }
}
