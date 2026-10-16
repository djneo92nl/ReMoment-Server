<?php

namespace App\Domain\Library;

use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use App\Models\Media\Playlist;
use Illuminate\Support\Facades\DB;

/** Creating, renaming, deleting and editing the tracks of playlists made on this server; shared by the web UI and the MCP server. */
class PlaylistEditor
{
    public function create(string $name, bool $smart = false): Playlist
    {
        $playlist = Playlist::create([
            'name' => trim($name),
            'source' => $smart ? 'smart' : 'local',
            'rules' => $smart ? SmartPlaylistBuilder::defaults() : null,
        ]);

        if ($smart) {
            SmartPlaylistBuilder::refresh($playlist);
        }

        return $playlist;
    }

    public function rename(Playlist $playlist, string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $playlist->update(['name' => $name]);

        return true;
    }

    /** False when the playlist comes from another source (Spotify) and must not be deleted here. */
    public function delete(Playlist $playlist): bool
    {
        if (!$playlist->isDeletable()) {
            return false;
        }

        $playlist->delete();

        return true;
    }

    /** Replaces the rules of a smart playlist (normalized) and re-selects its tracks. */
    public function setRules(Playlist $playlist, array $definition): array
    {
        $normalized = SmartPlaylistBuilder::normalize($definition);
        $playlist->update(['rules' => $normalized]);
        SmartPlaylistBuilder::refresh($playlist);

        return $normalized;
    }

    public function addTrack(Playlist $playlist, int $trackId): void
    {
        $playlist->tracks()->syncWithoutDetaching([$trackId => ['position' => $this->nextPosition($playlist)]]);
    }

    /**
     * Adds tracks in the given order; ones already in the playlist are ignored.
     *
     * @param  iterable<int>  $trackIds
     * @return int how many were new
     */
    public function addTracks(Playlist $playlist, iterable $trackIds): int
    {
        $before = $playlist->tracks()->count();
        foreach ($trackIds as $id) {
            $this->addTrack($playlist, (int) $id);
        }

        return $playlist->tracks()->count() - $before;
    }

    public function removeTrack(Playlist $playlist, int $trackId): void
    {
        $playlist->tracks()->detach($trackId);
        $this->resequence($playlist);
    }

    /** Moves a track one place up (-1) or down (+1). */
    public function moveTrack(Playlist $playlist, int $trackId, int $direction): void
    {
        $ids = $this->orderedTrackIds($playlist)->toArray();

        $pos = array_search($trackId, $ids);
        $target = $pos === false ? false : $pos + $direction;

        if ($pos === false || $target < 0 || $target >= count($ids)) {
            return;
        }

        [$ids[$pos], $ids[$target]] = [$ids[$target], $ids[$pos]];

        $this->writePositions($playlist, $ids);
    }

    private function nextPosition(Playlist $playlist): int
    {
        $max = DB::table('playlist_track')->where('playlist_id', $playlist->id)->max('position');

        return $max === null ? 0 : $max + 1;
    }

    private function resequence(Playlist $playlist): void
    {
        $this->writePositions($playlist, $this->orderedTrackIds($playlist));
    }

    private function orderedTrackIds(Playlist $playlist): \Illuminate\Support\Collection
    {
        return DB::table('playlist_track')
            ->where('playlist_id', $playlist->id)
            ->orderBy('position')
            ->pluck('track_id');
    }

    private function writePositions(Playlist $playlist, iterable $ids): void
    {
        foreach ($ids as $order => $id) {
            DB::table('playlist_track')
                ->where('playlist_id', $playlist->id)
                ->where('track_id', $id)
                ->update(['position' => $order]);
        }
    }
}
