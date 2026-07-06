<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_device', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->primary(['client_id', 'device_id']);
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_device');
    }
};
