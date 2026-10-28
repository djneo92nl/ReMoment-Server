<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('The MusicBrainz discography of the library: what an artist released (albums and EPs) and whether each is owned, streamable or only known (missing), and the household wishlist. action: discography (needs artist_id; optional availability owned|streamable|missing, wanted, ignored), wishlist (optional cursor), item (needs id, a catalogue entry), wish / unwish (needs id: put it on or take it off the household wishlist, something to buy or rip), ignore / unignore (needs id: "not interested", hides it), import (needs id: add an album Spotify has to the library), wish_song (needs artist and title, optional album: a song to buy or rip, e.g. heard on the radio), unwish_song (needs id of a wished song, see `songs` in the wishlist). Pass device_id to see where each entry can play. Full shapes: docs/api/availability.md.')]
class Catalogue extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['discography', 'wishlist', 'item', 'wish', 'unwish', 'ignore', 'unignore', 'import', 'wish_song', 'unwish_song'])->required(),
            'artist_id' => $schema->integer()->description('discography: the library artist'),
            'id' => $schema->integer()->description('item, wish, unwish, ignore, unignore, import: the catalogue entry id; unwish_song: the wished song id'),
            'artist' => $schema->string()->description('wish_song: the artist'),
            'title' => $schema->string()->description('wish_song: the song title'),
            'album' => $schema->string()->description('wish_song: the album, if known'),
            'availability' => $schema->string()->enum(['owned', 'streamable', 'missing'])->description('discography filter; missing = not in the library'),
            'wanted' => $schema->boolean()->description('discography: only wished entries'),
            'ignored' => $schema->boolean()->description('discography: include entries marked "not interested"'),
            'cursor' => $schema->string()->description('wishlist: next_cursor of the previous page'),
            'device_id' => $schema->integer()->description('Mark where each entry can play'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:discography,wishlist,item,wish,unwish,ignore,unignore,import,wish_song,unwish_song',
            'artist_id' => 'nullable|integer', 'id' => 'nullable|integer',
            'availability' => 'nullable|in:owned,streamable,missing', 'wanted' => 'nullable|boolean', 'ignored' => 'nullable|boolean',
            'cursor' => 'nullable|string', 'device_id' => 'nullable|integer',
            'artist' => 'nullable|string|max:255', 'title' => 'nullable|string|max:255', 'album' => 'nullable|string|max:255',
        ]);

        $common = $this->filled(['device_id' => $data['device_id'] ?? null]);

        if ($data['action'] === 'wish_song') {
            return ($data['artist'] ?? '') !== '' && ($data['title'] ?? '') !== ''
                ? $this->api('POST', 'library/wanted-tracks', $this->filled(['artist' => $data['artist'], 'title' => $data['title'], 'album' => $data['album'] ?? null, 'source' => 'manual']))
                : Response::error('invalid (422): wish_song needs an artist and a title.');
        }

        if ($data['action'] === 'unwish_song') {
            return isset($data['id'])
                ? $this->api('DELETE', 'library/wanted-tracks/'.$data['id'])
                : Response::error('invalid (422): unwish_song needs an id.');
        }

        if ($data['action'] === 'import') {
            return isset($data['id'])
                ? $this->api('POST', 'library/catalogue/'.$data['id'].'/import')
                : Response::error('invalid (422): import needs an id.');
        }

        if (in_array($data['action'], ['wish', 'unwish', 'ignore', 'unignore'], true)) {
            if (!isset($data['id'])) {
                return Response::error('invalid (422): '.$data['action'].' needs an id.');
            }
            $flag = str_contains($data['action'], 'wish') ? 'wanted' : 'ignored';

            return $this->api('PUT', 'library/catalogue/'.$data['id'].'/'.$flag, [$flag => !str_starts_with($data['action'], 'un')]);
        }

        return match ($data['action']) {
            'wishlist' => $this->api('GET', 'library/wishlist', $common + $this->filled(['cursor' => $data['cursor'] ?? null])),
            'item' => isset($data['id'])
                ? $this->api('GET', 'library/catalogue/'.$data['id'], $common)
                : Response::error('invalid (422): item needs an id.'),
            default => isset($data['artist_id'])
                ? $this->api('GET', 'library/artists/'.$data['artist_id'].'/discography', $common + $this->filled([
                    'availability' => $data['availability'] ?? null,
                    'wanted' => isset($data['wanted']) ? (int) $data['wanted'] : null,
                    'ignored' => isset($data['ignored']) ? (int) $data['ignored'] : null,
                ]))
                : Response::error('invalid (422): discography needs an artist_id.'),
        };
    }
}
