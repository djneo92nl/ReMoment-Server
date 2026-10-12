<?php

use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use Illuminate\Database\Migrations\Migration;

/** The AudioDB job now also keeps fanart 2–4 and the wide thumb: let artists that were done before be fetched again. */
return new class extends Migration
{
    public function up(): void
    {
        Metadata::where('metadatable_type', Artist::class)
            ->where('key', 'enriched_at')
            ->where('source', 'audiodb')
            ->delete();
    }

    public function down(): void {}
};
