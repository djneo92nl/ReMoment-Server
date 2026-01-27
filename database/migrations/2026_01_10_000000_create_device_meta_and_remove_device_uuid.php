<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('devices', 'uuid')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropColumn('uuid');
            });
        }

        Schema::create('device_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('key');
            $table->string('value')->nullable();
            $table->timestamps();
            $table->index(['key', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_meta');

        if (!Schema::hasColumn('devices', 'uuid')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->string('uuid');
            });
        }
    }
};
