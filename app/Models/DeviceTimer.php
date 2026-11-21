<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sleep timer or an alarm's volume ramp of a device; see App\Domain\Scenes\DeviceTimers. */
class DeviceTimer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'state' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
