<?php

namespace App\Domain\Storage;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LocalCover;
use App\Domain\Library\Enrichment;
use App\Jobs\ProcessArtwork;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\TrackFile;

/**
 * Gives an album the cover embedded in its own audio files. Only an album without an image gets one, so a
 * cover from Spotify, a DLNA server or a person is never replaced. An album whose files carry no usable
 * picture is remembered (`cover_checked_at`), so the backfill does not read its files again every night.
 */
class CoverExtractor
{
    public const SOURCE = 'local';

    /** Files of one album tried by the backfill before it gives up on it. */
    private const FILES_PER_ALBUM = 3;

    /** @param  array{mime: ?string, data: string}|null  $picture  from AudioTags::read() */
    public function apply(Album $album, ?array $picture): bool
    {
        if (!empty($album->images)) {
            return false;
        }

        $url = LocalCover::store($picture['data'] ?? null);
        if ($url === null) {
            return false;
        }

        $album->update(['images' => [['url' => $url]]]);

        if (!ArtworkCache::has($url)) {
            ProcessArtwork::dispatch($url);
        }

        return true;
    }

    /**
     * The covers of albums that have none yet, read from their files (up to $limit albums, newest first).
     *
     * @return array{found: int, checked: int}
     */
    public function backfill(int $limit = 50): array
    {
        $albums = Album::query()
            ->where(fn ($q) => $q->whereNull('images')->orWhere('images', '[]'))
            ->whereHas('tracks.trackFiles', fn ($f) => $f->where('is_primary', true))
            ->whereDoesntHave('metadata', fn ($m) => $m->where('key', 'cover_checked_at')->where('source', self::SOURCE))
            ->latest('id')
            ->limit($limit)
            ->get();

        $found = 0;

        foreach ($albums as $album) {
            $picture = $this->pictureOf($album);

            if ($picture !== null && $this->apply($album, $picture)) {
                $found++;
            }

            Enrichment::save($album, 'cover_checked_at', now()->toIso8601String(), 'string', self::SOURCE);
        }

        return ['found' => $found, 'checked' => $albums->count()];
    }

    /** The first usable embedded picture among the album's primary files. */
    private function pictureOf(Album $album): ?array
    {
        $files = File::query()
            ->where('status', File::PRESENT)
            ->whereIn('id', TrackFile::query()->where('is_primary', true)
                ->whereIn('track_id', $album->tracks()->select('id'))->select('file_id'))
            ->with('location')
            ->orderBy('id')
            ->limit(self::FILES_PER_ALBUM)
            ->get();

        foreach ($files as $file) {
            try {
                $tags = (new FileStore($file->location))->withLocalPath($file->path, fn (string $path) => AudioTags::read($path));
            } catch (\Throwable) {
                continue;
            }

            if (!empty($tags['picture']['data'])) {
                return $tags['picture'];
            }
        }

        return null;
    }
}
