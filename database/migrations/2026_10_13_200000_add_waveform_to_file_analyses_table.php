<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A few hundred peak values (0–100) of the audio, for drawing the waveform of a track.
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->json('waveform')->nullable()->after('genre_tags');
        });
    }

    public function down(): void
    {
        Schema::table('file_analyses', function (Blueprint $table) {
            $table->dropColumn('waveform');
        });
    }
};
