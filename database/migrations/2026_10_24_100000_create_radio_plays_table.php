<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radio_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('radio_station_id')->constrained()->cascadeOnDelete();
            $table->string('artist')->nullable();
            $table->string('title');
            $table->string('source', 20)->default('icy');
            $table->timestamp('started_at');
            $table->index(['radio_station_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radio_plays');
    }
};
