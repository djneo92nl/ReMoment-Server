<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Make ip_address nullable to support virtual devices (e.g. Spotify)
        Schema::table('devices', function (Blueprint $table) {
            $table->string('ip_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');

        Schema::table('devices', function (Blueprint $table) {
            $table->string('ip_address')->nullable(false)->change();
        });
    }
};
