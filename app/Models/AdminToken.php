<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A sign-in of the one shared admin from an app (one row per app install, so a phone can be revoked on its own).
 * These are not accounts: there is still exactly one admin user (see CLAUDE.md, Auth Model).
 */
class AdminToken extends Model
{
    protected $fillable = ['name', 'token', 'last_used_at', 'last_used_ip'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /** @return array{0: self, 1: string} the stored token and the plain token to hand out once */
    public static function issue(?string $name): array
    {
        $plain = 'rmt_'.Str::random(48);

        return [static::create(['name' => $name, 'token' => static::hash($plain)]), $plain];
    }

    public static function findPlain(string $plain): ?self
    {
        return static::where('token', static::hash($plain))->first();
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
