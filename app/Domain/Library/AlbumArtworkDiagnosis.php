<?php

namespace App\Domain\Library;

use App\Domain\Storage\TagEditor;
use App\Models\Media\Album;

/**
 * Explains, for an album page shown to the admin, why an album has no picture and what can be done about it:
 * which sources could have supplied one (embedded cover of an own file, Cover Art Archive through the MusicBrainz release, Spotify)
 * and where each stands. An empty list means the album has a picture.
 */
class AlbumArtworkDiagnosis
{
    /** @return list<string> */
    public static function reasons(Album $album): array
    {
        if (!empty($album->images)) {
            return [];
        }

        $reasons = [];
        $hasFiles = \App\Domain\Profile\Profile::allows('file_storage') && app(TagEditor::class)->albumFiles($album)->isNotEmpty();

        if ($hasFiles) {
            $reasons[] = 'None of the album\'s own files is known to hold an embedded cover that was picked up, so a picture can be added with Edit tags & cover.';
        } else {
            $reasons[] = 'The album has no files of its own, so there is no embedded cover to read; its tracks come from a DLNA server or Spotify that sent no picture.';
        }

        $mbid = Enrichment::mbid($album);
        if (!$mbid) {
            $reasons[] = 'No MusicBrainz release is matched to this album (the Cover Art Archive is looked up through it). The title or artist may be spelled differently there.';
        } elseif (!Enrichment::isDone($album, Enrichment::MUSICBRAINZ)) {
            $reasons[] = 'A MusicBrainz release is matched but its details have not been fetched yet; the cover is looked up then.';
        } else {
            $reasons[] = 'The matched MusicBrainz release has no front cover on the Cover Art Archive.';
        }

        return $reasons;
    }
}
