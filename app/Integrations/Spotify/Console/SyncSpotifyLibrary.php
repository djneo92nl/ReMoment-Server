<?php

namespace App\Console\Commands;

use App\Integrations\Spotify\Services\SpotifyLibraryImporter;
use App\Models\Setting;
use App\Services\SpotifyTokenService;
use Illuminate\Console\Command;

class SyncSpotifyLibrary extends Command
{
    protected $signature = 'library:sync-spotify {--tracks-only} {--playlists-only}';

    protected $description = 'Import saved tracks and playlists from the connected Spotify account';

    public function handle(SpotifyLibraryImporter $importer, SpotifyTokenService $tokenService): int
    {
        if (!$tokenService->isConnected()) {
            $this->error('Spotify is not connected. Connect it via Settings first.');

            return self::FAILURE;
        }

        if (!$tokenService->hasRequiredScopes()) {
            $this->error('Spotify is connected but missing library permissions. Reconnect Spotify via Settings.');

            return self::FAILURE;
        }

        if (!$this->option('playlists-only')) {
            $this->info('Importing saved tracks...');
            $count = $importer->importSavedTracks();
            $this->line("  → {$count} tracks imported.");
            Setting::set('spotify_library_tracks_synced_at', now()->toIso8601String());
        }

        if (!$this->option('tracks-only')) {
            $this->info('Importing playlists...');
            $count = $importer->importPlaylists();
            $this->line("  → {$count} playlists imported.");
            Setting::set('spotify_library_playlists_synced_at', now()->toIso8601String());
        }

        return self::SUCCESS;
    }
}
