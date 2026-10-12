<?php

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use Illuminate\Database\Migrations\Migration;

/**
 * TheAudioDB's public key "2" answered 404 for every lookup, so artists and albums were marked done without any
 * data. Clear the AudioDB marker of every record that has nothing from AudioDB, so the key "123" fetches them again.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([Artist::class, Album::class] as $type) {
            $withData = Metadata::where('metadatable_type', $type)
                ->where('source', 'audiodb')
                ->where('key', '!=', 'enriched_at')
                ->select('metadatable_id');

            Metadata::where('metadatable_type', $type)
                ->where('key', 'enriched_at')
                ->where('source', 'audiodb')
                ->whereNotIn('metadatable_id', $withData)
                ->delete();
        }
    }

    public function down(): void {}
};
