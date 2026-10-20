<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Play library content on a device (needs `library_playback`): a track, an album, an artist (all tracks) or a playlist. DLNA is tried first, then Spotify. Errors: not_playable (device cannot play it), no_dlna_url, unsupported, unreachable. Use `playlist_library` when playing a playlist of another library from Local.')]
class PlayFromLibrary extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'device_id' => $schema->integer()->required(),
            'type' => $schema->string()->enum(['track', 'album', 'artist', 'playlist'])->required(),
            'id' => $schema->integer()->description('Id of the track, album, artist or playlist (not needed with spotify_track_id)'),
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
            'device_id' => 'required|integer', 'type' => 'required|in:track,album,artist,playlist', 'id' => 'required_without:spotify_track_id|nullable|integer',
            'source' => 'nullable|in:files,dlna,spotify', 'spotify_track_id' => 'nullable|string',
            'start_track_id' => 'nullable|integer', 'shuffle' => 'nullable|boolean', 'playlist_library' => 'nullable|string',
        ]);

        $body = match ($data['type']) {
            'track' => ['track_id' => $data['id'] ?? null, 'spotify_track_id' => $data['spotify_track_id'] ?? null],
            'album' => ['album_id' => $data['id'], 'start_track_id' => $data['start_track_id'] ?? null, 'shuffle' => $data['shuffle'] ?? null, 'source' => $data['source'] ?? null],
            'artist' => ['artist_id' => $data['id'], 'shuffle' => $data['shuffle'] ?? null],
            'playlist' => ['playlist_id' => $data['id'], 'start_track_id' => $data['start_track_id'] ?? null, 'shuffle' => $data['shuffle'] ?? null, 'library' => $data['playlist_library'] ?? null],
        };

        $path = 'devices/'.$data['device_id'].'/library/'.($data['type'] === 'track' ? 'play' : 'play-'.$data['type']);

        return $this->api('POST', $path, $this->filled($body));
    }
}
