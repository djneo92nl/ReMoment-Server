<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSource extends Model
{
    protected $fillable = [
        'device_id',
        'source_id',
        'friendly_name',
        'source_type',
        'category',
        'in_use',
        'borrowed',
        'provider_jid',
        'provider_name',
    ];

    protected $casts = [
        'in_use' => 'boolean',
        'borrowed' => 'boolean',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
