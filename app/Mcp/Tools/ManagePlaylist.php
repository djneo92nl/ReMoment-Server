<?php

namespace App\Mcp\Tools;

use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\PlaylistEditor;
use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use App\Models\Media\Album;
use App\Models\Media\Playlist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create and edit playlists made on this server (Spotify playlists are read-only). actions: create (name, smart=true for a rule-based playlist), rename (id, name), delete (id, confirm=true), add_tracks / remove_tracks (id, track_ids), move_track (id, track_id, direction -1 up / +1 down), set_rules (id, rules = {match: all|any, rules: [{field, operator, value}], sort, limit}; smart playlists only, re-selects the tracks). Read playlists with browse_library kind=playlist; sorts are: random, recently_added, most_played, least_played, last_played, newest, …')]
class ManagePlaylist extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['create', 'rename', 'delete', 'add_tracks', 'remove_tracks', 'move_track', 'set_rules'])->required(),
            'id' => $schema->integer()->description('Playlist id (all actions but create)'),
            'name' => $schema->string()->max(255),
            'smart' => $schema->boolean()->description('create: a rule-based playlist'),
            'track_ids' => $schema->array()->items($schema->integer())->description('add_tracks / remove_tracks'),
            'album_id' => $schema->integer()->description('add_tracks: add the album\'s tracks in album order instead of track_ids'),
            'source' => $schema->string()->enum(['files', 'dlna', 'spotify'])->description('add_tracks with album_id: only the tracks of this source'),
            'track_id' => $schema->integer()->description('move_track'),
            'direction' => $schema->integer()->description('move_track: -1 or 1')->min(-1)->max(1),
            'rules' => $schema->object()->description('set_rules: the smart playlist definition'),
            'confirm' => $schema->boolean()->description('delete: must be true'),
        ];
    }

    public function handle(Request $request, PlaylistEditor $editor): Response
    {
        $data = $request->validate([
            'action' => 'required|in:create,rename,delete,add_tracks,remove_tracks,move_track,set_rules',
            'id' => 'nullable|integer', 'name' => 'nullable|string|max:255', 'smart' => 'nullable|boolean',
            'track_ids' => 'nullable|array', 'track_ids.*' => 'integer|exists:tracks,id',
            'album_id' => 'nullable|integer|exists:albums,id', 'source' => 'nullable|in:files,dlna,spotify',
            'track_id' => 'nullable|integer', 'direction' => 'nullable|integer|in:-1,1',
            'rules' => 'nullable|array', 'confirm' => 'nullable|boolean',
        ]);

        if ($data['action'] === 'create') {
            if (trim($data['name'] ?? '') === '') {
                return Response::error('invalid (422): create needs a name.');
            }

            $playlist = $editor->create($data['name'], (bool) ($data['smart'] ?? false));

            return Response::json(['status' => 'ok', ...$this->summary($playlist)]);
        }

        $playlist = isset($data['id']) ? Playlist::find($data['id']) : null;
        if (!$playlist) {
            return Response::error('not_found (404): no playlist with that id.');
        }

        if ($playlist->source !== 'local' && $playlist->source !== 'smart') {
            return Response::error('unsupported (422): only playlists made here can be edited.');
        }

        switch ($data['action']) {
            case 'rename':
                if (!$editor->rename($playlist, $data['name'] ?? '')) {
                    return Response::error('invalid (422): rename needs a name.');
                }
                break;
            case 'delete':
                if (empty($data['confirm'])) {
                    return Response::error("confirmation_required (422): deleting \"{$playlist->name}\" cannot be undone; call again with confirm=true.");
                }
                $editor->delete($playlist);

                return Response::json(['status' => 'ok', 'deleted' => $playlist->id]);
            case 'add_tracks':
            case 'remove_tracks':
                if ($playlist->isSmart()) {
                    return Response::error('unsupported (422): the tracks of a smart playlist come from its rules; use set_rules.');
                }
                if ($data['action'] === 'add_tracks' && !empty($data['album_id'])) {
                    $tracks = LibraryPlayback::albumTracks(Album::findOrFail($data['album_id']));
                    if (!empty($data['source'])) {
                        $tracks = $tracks->filter(fn ($t) => in_array($data['source'], $t->sources(), true));
                    }
                    $data['track_ids'] = $tracks->pluck('id')->map(fn ($id) => (int) $id)->all();
                }
                foreach ($data['track_ids'] ?? [] as $trackId) {
                    $data['action'] === 'add_tracks' ? $editor->addTrack($playlist, $trackId) : $editor->removeTrack($playlist, $trackId);
                }
                break;
            case 'move_track':
                if ($playlist->isSmart() || !isset($data['track_id'], $data['direction'])) {
                    return Response::error('invalid (422): move_track needs track_id and direction on a normal playlist.');
                }
                $editor->moveTrack($playlist, $data['track_id'], $data['direction']);
                break;
            case 'set_rules':
                if (!$playlist->isSmart()) {
                    return Response::error('unsupported (422): only smart playlists have rules.');
                }
                $editor->setRules($playlist, $data['rules'] ?? SmartPlaylistBuilder::defaults());
                break;
        }

        return Response::json(['status' => 'ok', ...$this->summary($playlist->fresh())]);
    }

    private function summary(Playlist $playlist): array
    {
        return [
            'id' => $playlist->id, 'name' => $playlist->name, 'source' => $playlist->source,
            'track_count' => $playlist->tracks()->count(), 'rules' => $playlist->rules,
        ];
    }
}
