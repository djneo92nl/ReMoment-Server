<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A browser that opted in to background alerts; no user (docs/frontend/pwa.md). */
class PushSubscription extends Model
{
    protected $fillable = ['endpoint', 'p256dh', 'auth', 'user_agent', 'last_success_at'];

    protected function casts(): array
    {
        return ['last_success_at' => 'datetime'];
    }
}
