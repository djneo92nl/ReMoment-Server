<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address');
            $table->string('uuid');
            $table->string('device_name');
            $table->string('device_brand_name')->nullable();
            $table->string('device_driver_name')->nullable();
            $table->string('device_product_type')->nullable();
            $table->string('device_driver')->nullable();
            $table->dateTime('last_seen')->nullable();
            $table->timestamps(); // created_at & updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
