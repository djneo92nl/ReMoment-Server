<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->enum('type', ['single', 'multi'])->default('single');
            $table->enum('status', ['pending', 'approved'])->default('pending');
            $table->string('hardware_id', 100)->nullable()->unique();
            $table->string('registration_token', 64)->unique();
            $table->string('api_token', 64)->nullable()->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('firmware_version', 50)->nullable();
            $table->unsignedInteger('build_number')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
