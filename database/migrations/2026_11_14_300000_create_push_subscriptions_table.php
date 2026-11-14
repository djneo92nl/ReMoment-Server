<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Background Web Push (docs/frontend/pwa.md, workstream 8.6 phase 3): one row per browser that opted in.
    // No user id: the server has one shared admin and no accounts.
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 700)->unique(); // the push service's URL for this browser; 700 fits the unique index on MariaDB
            $table->string('p256dh', 128); // the browser's public key (base64url)
            $table->string('auth', 64); // the browser's auth secret (base64url)
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
