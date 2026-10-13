<?php

namespace App\Domain\Storage;

use App\Models\File;
use App\Models\Media\Track;
use App\Models\StorageLocation;

/**
 * Puts files in the library in an organized layout: `{album artist}/{album} ({year})/{disc}-{track} {title}.{ext}`.
 * Moves within a location use the disk's move; across locations (or drivers) the file is copied, the copy's
 * hash verified, and only then the source removed.
 */
class LibraryOrganizer
{
    /** The layout path for a track's file. */
    public function pathFor(Track $track, File $file, ?string $ignoreExistingPath = null, ?FileStore $store = null): string
    {
        $track->loadMissing('album.artist', 'artist');

        $artist = $this->clean($track->album?->artist?->name ?? $track->artist?->name ?? '', 'Unknown Artist');
        $album = $this->clean($track->album?->name ?? '', 'Unknown Album');
        $year = $track->album?->released_at ? substr((string) $track->album->released_at, 0, 4) : null;
        $meta = $track->metadata()->whereIn('key', ['track_number', 'disc_number'])->pluck('value', 'key');

        $directory = $artist.'/'.$album.($year ? " ({$year})" : '');
        $prefix = '';
        if ($n = (int) ($meta['track_number'] ?? 0)) {
            $disc = (int) ($meta['disc_number'] ?? 0);
            $prefix = ($disc > 1 ? $disc.'-' : '').str_pad((string) $n, 2, '0', STR_PAD_LEFT).' ';
        }
        $name = $prefix.$this->clean($track->name, 'Untitled');
        $extension = $file->extension ?: pathinfo($file->path, PATHINFO_EXTENSION);

        $path = $directory.'/'.$name.'.'.$extension;

        if ($store !== null) {
            for ($i = 2; $store->exists($path) && $path !== $ignoreExistingPath; $i++) {
                $path = $directory.'/'.$name." ({$i}).".$extension;
            }
        }

        return $path;
    }

    /** Moves the file to its layout path in $target (the library location); returns the updated file. */
    public function organize(File $file, StorageLocation $target, bool $keepSource = false): File
    {
        $track = $file->tracks()->first();
        if ($track === null) {
            return $file; // unmatched files stay where they are until they have a track
        }

        $targetStore = new FileStore($target);
        $path = $this->pathFor($track, $file, $file->storage_location_id === $target->id ? $file->path : null, $targetStore);

        if ($file->storage_location_id === $target->id && $path === $file->path) {
            return $file;
        }

        return $this->relocate($file, $target, $path, $keepSource);
    }

    /**
     * Moves a file to $path on $target, whatever location it is on now. With $keepSource a file that crosses
     * locations is copied and verified but the original is left where it was (a move within one location
     * cannot keep a source).
     */
    public function relocate(File $file, StorageLocation $target, string $path, bool $keepSource = false): File
    {
        $source = new FileStore($file->location);
        $targetStore = new FileStore($target);

        if ($file->storage_location_id === $target->id) {
            $targetStore->move($file->path, $path);
        } else {
            $targetStore->put($path, $source->readStream($file->path));
            if ($targetStore->hash($path) !== $file->hash) {
                $targetStore->delete($path);
                throw new \RuntimeException("Copy of {$file->path} did not match its hash; the original was kept.");
            }
            if (!$keepSource) {
                $source->delete($file->path);
            }
        }

        $file->update([
            'storage_location_id' => $target->id,
            'path' => $path,
            'mtime' => $targetStore->lastModified($path),
        ]);

        return $file->refresh();
    }

    private function clean(string $value, string $fallback): string
    {
        $value = trim(preg_replace('/[\x00-\x1f\\\\\/:*?"<>|]+/u', '_', $value), " ._\t");

        return $value === '' ? $fallback : mb_substr($value, 0, 100);
    }
}
