<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncGroupMember extends Model
{
    protected $fillable = ['sync_group_id', 'device_id', 'offset_ms'];

    protected $casts = ['offset_ms' => 'integer'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(SyncGroup::class, 'sync_group_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
