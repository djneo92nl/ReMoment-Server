<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MultiroomPreset extends Model
{
    protected $fillable = ['name', 'device_ids'];

    protected $casts = [
        'device_ids' => 'array',
    ];
}
