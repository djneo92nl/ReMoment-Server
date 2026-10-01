<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Client extends Model
{
    protected $fillable = [
        'name', 'type', 'status', 'hardware_id',
        'registration_token', 'pairing_code', 'api_token',
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

    /** Unambiguous alphabet for codes a human reads off a screen: no 0/O, 1/I. */
    public const PAIRING_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const PAIRING_CODE_LENGTH = 6;

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /** A short code the client shows on its screen so the admin can match it in /settings/clients. */
    public static function generatePairingCode(): string
    {
        $max = strlen(self::PAIRING_CODE_ALPHABET) - 1;

        do {
            $code = '';
            for ($i = 0; $i < self::PAIRING_CODE_LENGTH; $i++) {
                $code .= self::PAIRING_CODE_ALPHABET[random_int(0, $max)];
            }
        } while (static::where('pairing_code', $code)->exists());

        return $code;
    }
}
