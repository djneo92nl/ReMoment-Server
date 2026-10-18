<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the quality stage measured (App\Domain\Analysis\Stages\QualityStage). Typed columns, so smart playlists can query them.
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->unsignedInteger('spectral_cutoff_hz')->nullable(); // where the spectrum falls off a cliff; null = none found
            $table->float('lossless_suspect')->nullable(); // 0–1 for lossless files: how likely a transcode or upsample
            $table->unsignedSmallInteger('measured_bit_depth')->nullable(); // bits the samples really use (lossless files)
            $table->unsignedInteger('clipped_samples')->nullable(); // times the signal sat at its own peak
            $table->boolean('clipping_suspect')->nullable();
            $table->boolean('is_mono')->nullable(); // identical channels
            $table->boolean('phase_issue')->nullable(); // channels largely out of phase
            $table->unsignedInteger('lead_silence_ms')->nullable();
            $table->unsignedInteger('trail_silence_ms')->nullable();
            $table->json('decode_warnings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->dropColumn([
                'spectral_cutoff_hz', 'lossless_suspect', 'measured_bit_depth', 'clipped_samples', 'clipping_suspect',
                'is_mono', 'phase_issue', 'lead_silence_ms', 'trail_silence_ms', 'decode_warnings',
            ]);
        });
    }
};
