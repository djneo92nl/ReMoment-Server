<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Play library content on a device (needs `library_playback`): a track, an album, an artist (all tracks), a playlist, or `type` tracks: shuffle the whole library (a random sample of 100 playable tracks, no `id`), or `type` similar: a radio from track `id` (that track, then up to 49 that sound most like it, in that order; needs the sound embedding, error not_analysed without it). `type` daily_mix: the daily mix of today (songs you loved and forgot, deep cuts, albums you never played, in turns; no `id`). `type` journey: a queue from track `id` to track `to_id` through the sound space (needs sound embeddings, Full; `length` 4-30). `type` artist_tour: a tour through artist `id` (the songs you play most, then one song of each album, oldest first). DLNA is tried first, then Spotify. Errors: not_playable (device cannot play it), no_dlna_url, unsupported, unreachable. Use `playlist_library` when playing a playlist of another library from Local.')]
class PlayFromLibrary extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->required(),
            'type' => $schema->string()->enum(['track', 'album', 'artist', 'playlist', 'tracks', 'similar', 'daily_mix', 'journey', 'artist_tour'])->required(),
            'id' => $schema->integer()->description('Id of the track, album, artist or playlist (not needed with spotify_track_id)'),
            'to_id' => $schema->integer()->description('journey: the track the journey ends at; `id` is the start'),
            'length' => $schema->integer()->description('journey: 4-30 tracks (default 12); artist_tour: 4-60 (default 15)'),
            'source' => $schema->string()->enum(['files', 'dlna', 'spotify'])->description('album: play only from this path; spotify plays the album from Spotify even when the library has none of its tracks'),
            'spotify_track_id' => $schema->string()->description('track: a Spotify track id the library does not hold (see album_spotify)'),
            'start_track_id' => $schema->integer()->description('album/playlist: start at this track'),
            'shuffle' => $schema->boolean()->description('album/artist/playlist: shuffle'),
            'playlist_library' => $schema->string()->description('playlist: the library it was browsed in (dlna) to play it locally'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'device_id' => 'required|integer', 'type' => 'required|in:track,album,artist,playlist,tracks,similar,daily_mix,journey,artist_tour', 'id' => 'required_without:spotify_track_id|nullable|integer',
            'source' => 'nullable|in:files,dlna,spotify', 'spotify_track_id' => 'nullable|string',
            'start_track_id' => 'nullable|integer', 'shuffle' => 'nullable|boolean', 'playlist_library' => 'nullable|string',
            'to_id' => 'required_if:type,journey|nullable|integer', 'length' => 'nullable|integer|min:4|max:60',
        ]);

        $body = match ($data['type']) {
            'track' => ['track_id' => $data['id'] ?? null, 'spotify_track_id' => $data['spotify_track_id'] ?? null],
            'album' => ['album_id' => $data['id'], 'start_track_id' => $data['start_track_id'] ?? null, 'shuffle' => $data['shuffle'] ?? null, 'source' => $data['source'] ?? null],
            'artist' => ['artist_id' => $data['id'], 'shuffle' => $data['shuffle'] ?? null],
            'tracks' => [],
            'daily_mix' => [],
            'journey' => ['from_track_id' => $data['id'] ?? null, 'to_track_id' => $data['to_id'] ?? null, 'length' => $data['length'] ?? null],
            'artist_tour' => ['artist_id' => $data['id'] ?? null, 'length' => $data['length'] ?? null],
            'similar' => ['track_id' => $data['id'] ?? null],
            'playlist' => ['playlist_id' => $data['id'], 'start_track_id' => $data['start_track_id'] ?? null, 'shuffle' => $data['shuffle'] ?? null, 'library' => $data['playlist_library'] ?? null],
        };

        $path = 'devices/'.$data['device_id'].'/library/'.($data['type'] === 'track' ? 'play' : 'play-'.str_replace('_', '-', $data['type']));

        return $this->api('POST', $path, $this->filled($body));
    }
}
