<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Browse the library. kind: libraries, recent-items, recent, favorites, random-album, artists, albums, tracks, genres, playlists (lists; page with `cursor`, order with `sort`, jump with `letter`) or artist, album, genre, playlist (detail; needs `id`, genre takes its slug) or mixes-with (tracks that mix well with track `id`: similar tempo and compatible key, closest first; page with `cursor`). Pass `device_id` on album/playlist detail to see which tracks that device can play. Kind `tracks` also takes sound filters (bpm_min/max, camelot, arousal/valence/danceability/instrumental, plr_min, mood, lossless_suspect_max, measured_bit_depth_min, clipping), `include=analysis`, `count_only` and sort plr|bpm|arousal: only analysed own files match (docs/api/sound-query.md). Full shapes: docs/api/library.md.')]
#[IsReadOnly]
class BrowseLibrary extends Tool
{
    use CallsApi;

    private const LISTS = ['libraries', 'recent-items', 'recent', 'favorites', 'random-album', 'artists', 'albums', 'tracks', 'genres', 'playlists'];

    private const DETAILS = ['artist' => 'artists', 'album' => 'albums', 'genre' => 'genres', 'playlist' => 'playlists'];

    /** Sound filters of GET /api/library/tracks: name => validation rule. */
    private const SOUND = [
        'bpm_min' => 'numeric', 'bpm_max' => 'numeric', 'camelot' => 'string|max:100', 'arousal_min' => 'numeric|between:0,1', 'arousal_max' => 'numeric|between:0,1',
        'valence_min' => 'numeric|between:0,1', 'valence_max' => 'numeric|between:0,1', 'danceability_min' => 'numeric|between:0,1', 'instrumental_min' => 'numeric|between:0,1',
        'plr_min' => 'numeric', 'mood' => 'string|max:100', 'lossless_suspect_max' => 'numeric|between:0,1', 'measured_bit_depth_min' => 'integer|min:1',
        'clipping' => 'integer|in:0,1', 'include' => 'string|in:analysis', 'count_only' => 'boolean',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum([...self::LISTS, ...array_keys(self::DETAILS), 'mixes-with'])->required(),
            'id' => $schema->string()->description('Artist/album/playlist id, or genre slug (detail kinds); the track id for mixes-with'),
            'cursor' => $schema->string()->description('next_cursor of the previous page'),
            'sort' => $schema->string()->description('artists: name|plays; albums: name|artist|year|added|plays; tracks: name|artist|added|plays'),
            'letter' => $schema->string()->description('A-Z or # to start at that letter (artists, albums)'),
            'scope' => $schema->string()->description('local, streaming, all or a library id; default is the leading source'),
            'device_id' => $schema->integer()->description('Mark what this device can play'),
            'limit' => $schema->integer()->description('recent / recent-items only: 1-100')->min(1)->max(100),
            'bpm_min' => $schema->number()->description('tracks: tempo at least'),
            'bpm_max' => $schema->number()->description('tracks: tempo at most'),
            'camelot' => $schema->string()->description('tracks: comma list of Camelot codes, e.g. 8A,9A'),
            'arousal_min' => $schema->number()->description('tracks: 0-1, raw values'),
            'arousal_max' => $schema->number(),
            'valence_min' => $schema->number(),
            'valence_max' => $schema->number(),
            'danceability_min' => $schema->number(),
            'instrumental_min' => $schema->number(),
            'plr_min' => $schema->number()->description('tracks: peak-to-loudness ratio in dB, at least'),
            'mood' => $schema->string()->description('tracks: comma list of happy, sad, aggressive, relaxed, party, acoustic, electronic'),
            'lossless_suspect_max' => $schema->number()->description('tracks: at most this suspicion of a transcode, 0-1'),
            'measured_bit_depth_min' => $schema->integer(),
            'clipping' => $schema->integer()->description('tracks: 1 clipping suspected, 0 not'),
            'include' => $schema->string()->enum(['analysis'])->description('tracks: add the sound summary to every row'),
            'count_only' => $schema->boolean()->description('tracks: answer { total } for the filters instead of a page'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'kind' => 'required|string',
            'id' => 'nullable|string',
            'cursor' => 'nullable|string', 'sort' => 'nullable|string', 'letter' => 'nullable|string', 'scope' => 'nullable|string',
            'device_id' => 'nullable|integer', 'limit' => 'nullable|integer|min:1|max:100',
            ...array_map(fn ($rule) => 'nullable|'.$rule, self::SOUND),
        ]);

        $query = $this->filled(array_intersect_key($data, array_flip(['cursor', 'sort', 'letter', 'scope', 'device_id', 'limit', ...array_keys(self::SOUND)])));

        if (in_array($data['kind'], self::LISTS, true)) {
            return $this->api('GET', 'library/'.$data['kind'], $query);
        }

        if ($data['kind'] === 'mixes-with' && ($data['id'] ?? '') !== '') {
            return $this->api('GET', 'library/tracks/'.rawurlencode($data['id']).'/mixes-with', $query);
        }

        if (!isset(self::DETAILS[$data['kind']]) || ($data['id'] ?? '') === '') {
            return Response::error('invalid (422): kind must be one of the list or detail kinds, and detail kinds need an id.');
        }

        return $this->api('GET', 'library/'.self::DETAILS[$data['kind']].'/'.rawurlencode($data['id']), $query);
    }
}
