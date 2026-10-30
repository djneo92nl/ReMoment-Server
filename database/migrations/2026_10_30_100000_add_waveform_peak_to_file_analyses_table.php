<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The raw peaks (0–100, relative to the loudest sample) next to the contrast-mapped `waveform`; internal,
        // for later views. The schema review (workstream 14.3) consolidates this with the other migrations.
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->json('waveform_peak')->nullable()->after('waveform');
        });
    }

    public function down(): void
    {
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->dropColumn('waveform_peak');
        });
    }
};
