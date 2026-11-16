<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The kept stem audio of a track (`stem_audio` on-demand stage): { format, bitrate, sample_rate, channels,
        // duration, service_version, bytes, files: { vocals|drums|bass|other: { name, bytes } } }; the files live on
        // the `analysis.stem_audio_disk` under `stems/{file id}`. null = not kept. The schema review (14.3) consolidates this.
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->json('stem_audio')->nullable()->after('stems');
        });
    }

    public function down(): void
    {
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->dropColumn('stem_audio');
        });
    }
};
