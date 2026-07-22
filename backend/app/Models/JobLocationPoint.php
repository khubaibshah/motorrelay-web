<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobLocationPoint extends Model
{
    protected $fillable = [
        'job_id', 'driver_id', 'latitude', 'longitude', 'accuracy',
        'heading', 'speed_kph', 'source', 'recorded_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy' => 'float',
        'heading' => 'float',
        'speed_kph' => 'float',
        'recorded_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
