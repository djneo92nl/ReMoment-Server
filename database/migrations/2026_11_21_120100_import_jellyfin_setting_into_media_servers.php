<?php

use App\Domain\Credentials\ServiceCredentials;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The one Jellyfin server of Settings › Services becomes a row of `media_servers` (docs/architecture/media-servers.md).
     * A data migration: it only reads settings, so it is idempotent (nothing happens when a Jellyfin row exists or no
     * key is set) and left out of the schema squash. The row points at the credentials ('jellyfin'), it copies no secret.
     */
    public function up(): void
    {
        if (!Schema::hasTable('media_servers') || !Schema::hasTable('settings')) {
            return;
        }

        if (DB::table('media_servers')->where('driver', 'jellyfin')->exists() || !ServiceCredentials::configured('jellyfin')) {
            return;
        }

        DB::table('media_servers')->insert([
            'driver' => 'jellyfin',
            'name' => 'Jellyfin',
            'base_url' => null,
            'credential' => 'jellyfin',
            'enabled' => true,
            'meta' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Nothing to undo: the credentials stay where they were, and a row the owner has since edited is not ours to delete. */
    public function down(): void {}
};
