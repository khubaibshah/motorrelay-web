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
        'type',
        'status',
        'recovery_required',
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
}
