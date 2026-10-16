<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Own music files stop posing as the DLNA library: their stream URL metadata moves from source
 * `dlna:local` to `files`, and tracks that exist only as an own file get source `files`. A track that a
 * DLNA server also serves keeps `dlna` as its own source (it is available from both). Clients that hid
 * DLNA also hid the own files, so they hide Local files as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('metadata')->where('key', 'dlna_url')->where('source', 'dlna:local')->update(['source' => 'files']);

        $morph = (new \App\Models\Media\Track)->getMorphClass();
        DB::table('tracks')->where('source', 'dlna')
            ->whereExists(fn ($m) => $m->selectRaw('1')->from('metadata')
                ->whereColumn('metadata.metadatable_id', 'tracks.id')->where('metadata.metadatable_type', $morph)
                ->where('metadata.key', 'dlna_url')->where('metadata.source', 'files'))
            ->whereNotExists(fn ($m) => $m->selectRaw('1')->from('metadata')
                ->whereColumn('metadata.metadatable_id', 'tracks.id')->where('metadata.metadatable_type', $morph)
                ->where('metadata.key', 'dlna_url')->where('metadata.source', 'like', 'dlna:%'))
            ->update(['source' => 'files']);

        foreach (DB::table('clients')->whereNotNull('hidden_sources')->get(['id', 'hidden_sources']) as $client) {
            $hidden = json_decode($client->hidden_sources, true) ?: [];
            if (in_array('dlna', $hidden, true) && !in_array('files', $hidden, true)) {
                DB::table('clients')->where('id', $client->id)->update(['hidden_sources' => json_encode([...$hidden, 'files'])]);
            }
        }
    }

    public function down(): void
    {
        DB::table('tracks')->where('source', 'files')->update(['source' => 'dlna']);
        DB::table('metadata')->where('key', 'dlna_url')->where('source', 'files')->update(['source' => 'dlna:local']);
    }
};
