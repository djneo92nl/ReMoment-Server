<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('source_id');
            $table->string('friendly_name');
            $table->string('source_type');
            $table->string('category');
            $table->boolean('in_use')->default(true);
            $table->boolean('borrowed')->default(false);
            $table->string('provider_jid')->nullable();
            $table->string('provider_name')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sources');
    }
};
