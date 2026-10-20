<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovered_devices', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->unique();
            $table->string('device_name');
            $table->string('device_brand_name');
            $table->string('device_product_type');
            $table->string('device_driver');
            $table->string('device_driver_name');
            $table->json('meta')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ignored_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovered_devices');
    }
};
