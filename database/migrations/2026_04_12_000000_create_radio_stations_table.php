<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radio_stations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('image_url')->nullable();
            $table->timestamps();
        });

        Schema::create('radio_station_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('radio_station_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('value');
            $table->unique(['radio_station_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radio_station_meta');
        Schema::dropIfExists('radio_stations');
    }
};
