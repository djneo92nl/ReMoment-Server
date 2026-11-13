<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Date-range queries over all devices (statistics, history API, recent plays) have no index to start from: (device_id, played_at) only helps per device. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->index('played_at');
        });
    }

    public function down(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->dropIndex(['played_at']);
        });
    }
};
