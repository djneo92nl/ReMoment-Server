<?php

namespace App\Console\Commands\Library;

use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use App\Models\Media\Playlist;
use Illuminate\Console\Command;

class RefreshSmartPlaylists extends Command
{
    protected $signature = 'playlists:refresh-smart {--playlist= : Only this playlist id}';

    protected $description = 'Re-select the tracks of smart playlists from their rules (scheduled daily, so "not played lately" and random lists stay fresh)';

    public function handle(): int
    {
        $playlists = Playlist::query()->where('source', 'smart')
            ->when($this->option('playlist'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        foreach ($playlists as $playlist) {
            $count = SmartPlaylistBuilder::refresh($playlist);
            $this->line("{$playlist->name}: {$count} track(s)");
        }

        $this->info("Refreshed {$playlists->count()} smart playlist(s).");

        return self::SUCCESS;
    }
}
