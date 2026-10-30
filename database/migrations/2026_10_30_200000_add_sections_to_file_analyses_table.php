<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The sections of a track (workstream 16.2, `structure` on-demand stage): { method, service_version, tempo,
        // reason, items: [{start, end, label, confidence}] }; null = never computed. The schema review (workstream
        // 14.3) consolidates this with the other migrations.
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->json('sections')->nullable()->after('waveform_peak');
        });
    }

    public function down(): void
    {
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->dropColumn('sections');
        });
    }
};
