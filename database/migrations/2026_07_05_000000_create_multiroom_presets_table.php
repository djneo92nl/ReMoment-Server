<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('multiroom_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('device_ids');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multiroom_presets');
    }
};
