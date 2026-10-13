<?php

namespace App\Domain\Library;

use App\Domain\Storage\StreamUrl;
use App\Models\Media\Metadata;
use App\Models\Media\Track;

/** Finds the library track behind a DLNA stream URL, e.g. one read back from a player's play queue. */
final class DlnaTrackLookup
{
    /**
     * By the exact URL, else by the URL without its query string: a DLNA server such as Jellyfin
     * puts a per-session tag in it, the path names the item.
     */
    public static function byUrl(string $url): ?Track
    {
        // Our own files: the URL is built when played from the current base address, so match on the track id.
        if (($id = StreamUrl::trackId($url)) !== null) {
            return Track::query()->with(['artist', 'album'])->find($id);
        }

        $query = fn () => Metadata::query()->where('metadatable_type', Track::class)->where('key', 'dlna_url');

        $id = $query()->where('value', $url)->value('metadatable_id');

        if (!$id && ($path = strtok($url, '?')) !== false && $path !== '') {
            $escaped = addcslashes($path, '%_\\');
            $id = $query()->where('value', 'like', $escaped.'?%')->value('metadatable_id');
        }

        return $id ? Track::query()->with(['artist', 'album'])->find($id) : null;
    }
}
