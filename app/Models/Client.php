<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Client extends Model
{
    protected $fillable = [
        'name', 'type', 'status', 'hardware_id',
        'registration_token', 'api_token',
        'ip_address', 'firmware_version', 'build_number',
        'metadata', 'last_seen_at', 'approved_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_seen_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class)
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }
}
