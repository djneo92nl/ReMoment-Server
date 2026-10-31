<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The stems of a track (workstream 16.4, `stems` on-demand stage): { method, service_version, rate_hz,
        // vocal_ratio, instrumental: [{start, end}], lanes: {vocals, drums, bass, other: [int dB]} }; null = never
        // computed. The schema review (workstream 14.3) consolidates this with the other migrations.
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->json('stems')->nullable()->after('sections');
        });
    }

    public function down(): void
    {
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->dropColumn('stems');
        });
    }
};
