<?php

namespace App\Domain\Storage;

use App\Domain\Catalogue\CatalogueLinker;
use App\Domain\Library\Enrichment;
use App\Domain\Library\LibraryIdentity;
use App\Domain\Library\ReleaseDate;
use App\Models\File;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;

/**
 * Describes a stored file in the `files` table and attaches it to its track (find-or-create through
 * LibraryIdentity). A file is identified by its content hash, so a file that turns up at a new path is a
 * move: the row (and so the track) is kept.
 */
class FileIndexer
{
    public const UNCHANGED = 'unchanged';

    public const NEW = 'new';

    public const UPDATED = 'updated';

    public const MOVED = 'moved';

    public const SKIPPED = 'skipped';

    /** Source of the track metadata written from the file's tags and properties. */
    public const TAG_SOURCE = 'local';

    /** Metadata source of the stream URL: the Local library (see LibrarySources). */
    public const URL_SOURCE = 'files';

    /** @return array{0: ?File, 1: string} the file row and what happened */
    public function index(StorageLocation $location, string $path, ?FileStore $store = null): array
    {
        $store ??= new FileStore($location);

        if (!AudioTags::isAudio($path)) {
            return [null, self::SKIPPED];
        }

        $size = $store->size($path);
        $mtime = $store->lastModified($path);

        $row = File::where('storage_location_id', $location->id)->where('path_hash', hash('sha256', $path))->first();
        if ($row && $row->size === $size && $row->mtime === $mtime && $row->status === File::PRESENT && $row->trackFiles()->exists()) {
            return [$row, self::UNCHANGED];
        }

        $hash = $store->hash($path);
        $outcome = $row ? self::UPDATED : self::NEW;

        if ($row === null && ($moved = $this->movedFrom($location, $store, $hash, $path))) {
            $moved->path = $path;
            $row = $moved;
            $outcome = self::MOVED;
        }

        $tags = $store->withLocalPath($path, fn (string $local) => AudioTags::read($local));
        if ($tags === null) {
            return [null, self::SKIPPED];
        }

        $row ??= new File(['storage_location_id' => $location->id, 'path' => $path]);
        $row->fill([
            'hash' => $hash, 'size' => $size, 'mtime' => $mtime, 'status' => File::PRESENT,
            'mime_type' => $tags['mime_type'], 'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'codec' => $tags['codec'], 'bitrate' => $tags['bitrate'], 'sample_rate' => $tags['sample_rate'],
            'bit_depth' => $tags['bit_depth'], 'channels' => $tags['channels'], 'duration' => $tags['duration'],
        ])->save();

        $this->attach($row, $tags);

        return [$row, $outcome];
    }

    /**
     * Matches (or creates) the track for the file's tags and links it as the original. A file that was
     * linked to another track before (retagged) moves over; the old track is left for the caller to clean.
     */
    public function attach(File $file, array $tags): Track
    {
        $fallbackTitle = pathinfo($file->path, PATHINFO_FILENAME);
        $artistName = $tags['artist'] ?? $tags['album_artist'] ?? 'Unknown Artist';
        $albumArtistName = $tags['album_artist'] ?? $artistName;

        $artist = LibraryIdentity::artist($artistName, 'files');
        $albumArtist = $albumArtistName === $artistName ? $artist : LibraryIdentity::artist($albumArtistName, 'files');

        $released = ReleaseDate::parse($tags['year']);
        $album = LibraryIdentity::album($albumArtist, $tags['album'] ?? 'Unknown Album', 'files',
            $released ? ['released_at' => $released] : []);

        app(CoverExtractor::class)->apply($album, $tags['picture'] ?? null);

        $track = LibraryIdentity::track($artist, $album, $tags['title'] ?? $fallbackTitle, 'local:file:'.$file->id, 'files', [
            'duration' => $tags['duration'],
        ]);

        Enrichment::save($track, 'dlna_url', StreamUrl::for($track->id), 'url', self::URL_SOURCE);
        $this->saveTags($track, $file, $tags);

        TrackFile::where('file_id', $file->id)->where('track_id', '!=', $track->id)->where('role', TrackFile::ORIGINAL)->delete();
        TrackFile::updateOrCreate(
            ['track_id' => $track->id, 'file_id' => $file->id],
            ['role' => TrackFile::ORIGINAL, 'is_primary' => true],
        );

        // An own file makes the album owned: closes a wish on its release-group (docs/architecture/availability-layer.md).
        CatalogueLinker::refreshOwnershipQuietly($album);

        return $track;
    }

    private function saveTags(Track $track, File $file, array $tags): void
    {
        $values = array_filter([
            'file_size' => [(string) $file->size, 'int'],
            'mime_type' => $file->mime_type ? [$file->mime_type, 'string'] : null,
            'bitrate' => $file->bitrate ? [(string) $file->bitrate, 'int'] : null,
            'sample_rate' => $file->sample_rate ? [(string) $file->sample_rate, 'int'] : null,
            'bit_depth' => $file->bit_depth ? [(string) $file->bit_depth, 'int'] : null,
            'channels' => $file->channels ? [(string) $file->channels, 'int'] : null,
            'track_number' => $tags['track_number'] ? [(string) $tags['track_number'], 'int'] : null,
            'disc_number' => $tags['disc_number'] ? [(string) $tags['disc_number'], 'int'] : null,
            'album_artist' => $tags['album_artist'] ? [$tags['album_artist'], 'string'] : null,
            'genres' => $tags['genres'] ? [json_encode($tags['genres'], JSON_UNESCAPED_UNICODE), 'json'] : null,
            'mbid' => $tags['mbid'] ? [$tags['mbid'], 'string'] : null,
        ]);

        foreach ($values as $key => [$value, $type]) {
            Enrichment::save($track, $key, $value, $type, self::TAG_SOURCE);
        }
    }

    /** A row with this content at another path whose file is gone (or marked missing): the file moved. */
    private function movedFrom(StorageLocation $location, FileStore $store, string $hash, string $newPath): ?File
    {
        return File::where('storage_location_id', $location->id)
            ->where('hash', $hash)->where('path', '!=', $newPath)
            ->get()
            ->first(fn (File $f) => $f->status === File::MISSING || !$store->exists($f->path));
    }
}
