<?php

namespace App\Console\Commands\Library;

use App\Domain\Library\SpotifyLibraryPurge;
use Illuminate\Console\Command;

class DropSpotifyLibrary extends Command
{
    protected $signature = 'library:drop-spotify {--dry-run : Only count what would go} {--force : Do not ask for confirmation}';

    protected $description = 'Remove what an earlier Spotify import left in the library (tracks only Spotify has, Spotify playlists, empty albums and artists); the library is the local tracks';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            $c = SpotifyLibraryPurge::run(dryRun: true);
            $this->line("{$c['tracks']} Spotify tracks and {$c['playlists']} Spotify playlists would go.");

            return self::SUCCESS;
        }
        if (!$this->option('force') && !$this->confirm('Remove the Spotify tracks and playlists from the library? Plays stay as text plays; favorites on them are lost.')) {
            return self::FAILURE;
        }

        $c = SpotifyLibraryPurge::run();
        $this->info("Removed {$c['tracks']} tracks, {$c['playlists']} playlists, {$c['albums']} albums and {$c['artists']} artists.");

        return self::SUCCESS;
    }
}
