<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceMeta extends Model
{
    protected $table = 'device_meta';

    public $fillable = [
        'device_id',
        'key',
        'value',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
