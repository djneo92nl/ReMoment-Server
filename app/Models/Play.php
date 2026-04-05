<?php

namespace App\Models;

use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Play extends Model
{
    protected $fillable = [
        'device_id', 'track_id', 'source_type', 'radio_name', 'source_name', 'played_at', 'ended_at',
    ];

    protected $casts = [
        'played_at' => 'datetime',
        'ended_at'  => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
