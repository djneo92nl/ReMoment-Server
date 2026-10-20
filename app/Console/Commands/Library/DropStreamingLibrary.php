<?php

namespace App\Console\Commands\Library;

use App\Domain\Library\LibraryRemover;
use App\Domain\Library\LibrarySources;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DropStreamingLibrary extends Command
{
    private const STREAMING = ['dlna', 'spotify'];

    protected $signature = 'library:drop-streaming
        {--dry-run : Only count what would go}
        {--force : Do not ask for confirmation}';

    protected $description = 'Remove the DLNA and Spotify library data (tracks without an own file, Spotify playlists); the scans and the Spotify sync bring it back';

    public function handle(): int
    {
        $tracks = $this->tracks();
        $playlists = Playlist::query()->where('source', 'spotify');

        $this->line(sprintf('%d streaming-only tracks and %d Spotify playlists (own files, radio and devices stay).', $tracks->count(), $playlists->count()));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (!$this->option('force') && !$this->confirm('Remove them? Plays of these tracks stay as text plays; favorites on them are lost.')) {
            return self::FAILURE;
        }

        $removed = 0;
        $this->tracks()->chunkById(500, function ($chunk) use (&$removed) {
            foreach ($chunk as $track) {
                $removed += LibraryRemover::track($track, force: true) ? 1 : 0;
            }
        });

        $lists = 0;
        foreach (Playlist::query()->where('source', 'spotify')->get() as $playlist) {
            $playlist->tracks()->detach();
            $playlist->metadata()->delete();
            $playlist->delete();
            $lists++;
        }

        // Albums and artists that never had tracks of their own (the remover only cleans up after a track).
        $albums = 0;
        foreach (Album::query()->whereIn('source', self::STREAMING)->whereDoesntHave('tracks')->get() as $album) {
            $this->forget($album);
            $albums++;
        }
        $artists = 0;
        foreach (Artist::query()->whereIn('source', self::STREAMING)->whereDoesntHave('tracks')->whereDoesntHave('albums')->get() as $artist) {
            $this->forget($artist);
            $artists++;
        }

        $this->info("Removed {$removed} tracks, {$lists} playlists, {$albums} more albums and {$artists} more artists.");

        return self::SUCCESS;
    }

    /** Tracks from DLNA or Spotify that no own file backs: radio stubs and anything available from `files` stay. */
    private function tracks(): Builder
    {
        return Track::query()
            ->whereIn('tracks.source', self::STREAMING)
            ->whereNot(fn (Builder $q) => LibrarySources::availableFrom($q, 'files'));
    }

    private function forget(Model $model): void
    {
        $model->metadata()->delete();
        $model->genreRelation()->detach();
        $model->delete();
    }
}
