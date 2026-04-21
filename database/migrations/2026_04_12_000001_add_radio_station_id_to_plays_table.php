<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->foreignId('radio_station_id')
                ->nullable()
                ->after('radio_name')
                ->constrained('radio_stations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('radio_station_id');
        });
    }
};
