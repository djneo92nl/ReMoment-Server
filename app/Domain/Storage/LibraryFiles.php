<?php

namespace App\Domain\Storage;

use App\Domain\Analysis\AudioAnalysis;
use App\Domain\Library\Enrichment;
use App\Domain\Library\LibraryRemover;
use App\Models\File;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use Illuminate\Support\Str;

/**
 * Managing the library's files: upload and import into the organized library, delete, move/rename, write
 * tags back into a file, verify, find duplicates. Bytes go through FileStore, rows through FileIndexer.
 */
class LibraryFiles
{
    public function __construct(
        private FileIndexer $indexer,
        private LibraryOrganizer $organizer,
    ) {}

    /**
     * Stores an uploaded file in the inbox (or the library when there is no inbox), indexes it and moves it
     * into the library layout.
     *
     * @param  resource  $stream
     */
    public function upload(string $filename, $stream): File
    {
        [$location, $path] = $this->stageUpload($filename, $stream);

        return $this->placeInLibrary($this->indexStaged($location, $path));
    }

    /**
     * First step of an upload: stores the bytes in the library location, under `_uploads/`. Not in the inbox:
     * that is often the existing music, mounted read-only, and the file is moved into the library layout
     * within the same location afterwards (a rename, not a copy).
     *
     * @param  resource  $stream
     * @return array{0: StorageLocation, 1: string} where the file now is
     */
    public function stageUpload(string $filename, $stream): array
    {
        $library = StorageLocation::library() ?? throw new \InvalidArgumentException('Add a library storage location first.');

        $filename = basename(str_replace('\\', '/', $filename));
        if (!AudioTags::isAudio($filename)) {
            throw new \InvalidArgumentException('Not an audio file.');
        }

        $path = '_uploads/'.Str::ulid().'/'.$filename;
        (new FileStore($library))->put($path, $stream);

        return [$library, $path];
    }

    /** Second step: reads the staged file's tags and attaches it to its track; an unreadable file is removed again. */
    public function indexStaged(StorageLocation $location, string $path): File
    {
        $store = new FileStore($location);

        [$file] = $this->indexer->index($location, $path, $store);
        if ($file === null) {
            $store->delete($path);
            throw new \InvalidArgumentException('The file could not be read as audio.');
        }

        return $file;
    }

    /** Third step: moves the file to its place in the library layout. */
    public function placeInLibrary(File $file): File
    {
        $library = StorageLocation::library() ?? throw new \InvalidArgumentException('Add a library storage location first.');
        $staged = $file->path;

        $file = $this->place($file, $library);

        // The staging folder is empty once its file has been moved to the layout path.
        if (str_starts_with($staged, '_uploads/') && $file->path !== $staged) {
            (new FileStore($library))->deleteDirectory(dirname($staged));
        }

        return $file;
    }

    /**
     * Indexes everything in the inbox and moves it into the library. With $keepSource the files are copied
     * and verified but stay in the inbox; a later import skips those whose content is already in the library.
     *
     * Then queues enrichment and analysis for a batch of the new tracks, as a scan does.
     *
     * @return int files moved (copied)
     */
    public function importInbox(bool $keepSource = false): int
    {
        $library = StorageLocation::library() ?? throw new \InvalidArgumentException('Add a library storage location first.');
        $inbox = StorageLocation::inbox() ?? throw new \InvalidArgumentException('Add an inbox storage location first.');

        $store = new FileStore($inbox);
        $moved = 0;

        foreach ($store->files() as $path => $_) {
            if ($keepSource && $this->inLibrary($library, $store, $path)) {
                continue;
            }

            [$file] = $this->indexer->index($inbox, $path, $store);
            if ($file && $file->storage_location_id === $inbox->id) {
                $this->place($file, $library, $keepSource);
                $moved++;
            }
        }

        if ($moved > 0) { // like a scan: a bounded batch now, the daily jobs work off the rest
            Enrichment::queueBacklog(Enrichment::SCAN_BATCH);
            AudioAnalysis::queueBacklog((int) config('analysis.scan_batch'));
        }

        return $moved;
    }

    /** Re-applies the library layout to every file in the library; with $dryRun only reports `[file, newPath]`. */
    public function reorganize(bool $dryRun = false): array
    {
        $library = StorageLocation::library() ?? throw new \InvalidArgumentException('Add a library storage location first.');
        $store = new FileStore($library);
        $changes = [];

        foreach ($library->files()->where('status', File::PRESENT)->get() as $file) {
            $track = $file->tracks()->first();
            if ($track === null) {
                continue;
            }
            $path = $this->organizer->pathFor($track, $file, $file->path, $store);
            if ($path === $file->path) {
                continue;
            }
            $changes[] = [$file, $path];
            if (!$dryRun) {
                $this->organizer->relocate($file, $library, $path);
            }
        }

        return $changes;
    }

    /** Removes a file's bytes and row; tracks left without a file are removed (or just unlinked from our stream). */
    public function deleteFile(File $file): void
    {
        $store = new FileStore($file->location);
        $trackIds = $file->trackFiles()->pluck('track_id')->all();

        if ($store->exists($file->path)) {
            $store->delete($file->path);
        }
        $file->delete();

        $this->releaseTracks($trackIds);
    }

    /** Removes every file of the track (original and proxies), then the track itself. */
    public function deleteTrack(Track $track): void
    {
        foreach (File::whereIn('id', $track->trackFiles()->pluck('file_id'))->get() as $file) {
            $this->deleteFile($file);
        }

        $this->releaseTracks([$track->id]);
    }

    /** Moves or renames a file inside its location (or to another location when $target is given). */
    public function move(File $file, string $path, ?StorageLocation $target = null): File
    {
        $target ??= $file->location;
        $path = ltrim($path, '/');

        if (str_contains($path, '..') || $path === '') {
            throw new \InvalidArgumentException('Invalid path.');
        }
        if ((new FileStore($target))->exists($path)) {
            throw new \InvalidArgumentException("{$path} already exists.");
        }

        return $this->organizer->relocate($file, $target, $path);
    }

    /**
     * Writes tags into the file (title, artist, album, album_artist, year, track_number, disc_number, genre),
     * stores it back, re-reads it and, when it is in the library, re-files it. Needs the tag tool of the
     * format (mp3 works without one; flac needs metaflac, ogg vorbiscomment).
     *
     * @param  array<string, string|int>  $tags
     */
    public function writeTags(File $file, array $tags): File
    {
        $formats = match ($file->extension) {
            'mp3' => ['id3v2.3'],
            'flac' => ['metaflac'],
            'ogg', 'oga', 'opus' => ['vorbiscomment'],
            default => throw new \InvalidArgumentException("Writing tags to .{$file->extension} files is not supported."),
        };

        $map = ['title' => 'title', 'artist' => 'artist', 'album' => 'album', 'album_artist' => 'band', 'year' => 'year',
            'track_number' => 'track_number', 'disc_number' => 'part_of_a_set', 'genre' => 'genre'];
        $data = [];
        foreach ($map as $key => $id3) {
            if (isset($tags[$key]) && $tags[$key] !== '') {
                $data[$id3] = [(string) $tags[$key]];
            }
        }
        if ($data === []) {
            throw new \InvalidArgumentException('No tags given.');
        }

        $store = new FileStore($file->location);
        $oldTrackIds = $file->trackFiles()->pluck('track_id')->all();

        $store->withLocalPath($file->path, function (string $tmp) use ($formats, $data, $store, $file) {
            $writer = new \getid3_writetags;
            $writer->filename = $tmp;
            $writer->tagformats = $formats;
            $writer->overwrite_tags = true;
            $writer->remove_other_tags = false;
            $writer->tag_encoding = 'UTF-8';
            $writer->tag_data = $data;

            if (!$writer->WriteTags()) {
                throw new \RuntimeException('Writing tags failed: '.implode('; ', $writer->errors));
            }

            $in = fopen($tmp, 'rb');
            $store->put($file->path, $in);
            fclose($in);
        }, writable: true);

        [$file] = $this->indexer->index($file->location, $file->path, $store);
        $this->releaseTracks(array_diff($oldTrackIds, $file?->trackFiles()->pluck('track_id')->all() ?? []));

        if ($file && $file->location->role === StorageLocation::ROLE_LIBRARY) {
            $file = $this->organizer->organize($file, $file->location);
        }

        return $file;
    }

    /**
     * Writes tags (see writeTags) and queues enrichment for the track the file now matches, so a
     * corrected tag is also looked up again.
     *
     * @param  array<string, string|int>  $tags
     */
    public function retag(File $file, array $tags): File
    {
        $file = $this->writeTags($file, $tags);
        $this->enrich($file);

        return $file;
    }

    /**
     * Matches the file to its track again from the tags it holds now. The one place where a file is tied
     * to a track after the fact, so a change in how songs are matched is made in FileIndexer::attach.
     */
    public function rematch(File $file): File
    {
        $oldTrackIds = $file->trackFiles()->pluck('track_id')->all();

        $file->update(['size' => -1]); // get past the indexer's size/mtime shortcut
        [$indexed] = $this->indexer->index($file->location, $file->path);
        $file = $indexed ?? $file->refresh();

        $this->releaseTracks(array_diff($oldTrackIds, $file->trackFiles()->pluck('track_id')->all()));
        $this->enrich($file);

        return $file;
    }

    /** Queues the enrichment jobs the file's track still misses; returns how many. */
    public function enrich(File $file): int
    {
        $track = $file->tracks()->with(['artist', 'album'])->first();

        return $track ? Enrichment::queue($track) : 0;
    }

    /** Hides the file (or shows it again) in the library; the bytes and the row stay. */
    public function setHidden(File $file, bool $hidden): File
    {
        $file->update(['hidden_at' => $hidden ? ($file->hidden_at ?? now()) : null]);

        return $file;
    }

    /** Hides or shows every file under a folder of a location; returns how many changed. */
    public function setFolderHidden(StorageLocation $location, string $dir, bool $hidden): int
    {
        $prefix = $dir === '' ? '' : rtrim($dir, '/').'/';

        return File::where('storage_location_id', $location->id)
            ->when($prefix !== '', fn ($q) => $q->where('path', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%'))
            ->when($hidden, fn ($q) => $q->whereNull('hidden_at'), fn ($q) => $q->whereNotNull('hidden_at'))
            ->update(['hidden_at' => $hidden ? now() : null]);
    }

    /** @return 'ok'|'missing'|'changed' — `changed` means the content no longer matches the stored hash (re-indexed). */
    public function verify(File $file): string
    {
        $store = new FileStore($file->location);

        if (!$store->exists($file->path)) {
            $file->update(['status' => File::MISSING]);

            return 'missing';
        }
        if ($store->hash($file->path) === $file->hash) {
            $file->update(['status' => File::PRESENT]);

            return 'ok';
        }

        $file->update(['size' => -1]); // force the indexer past its size/mtime shortcut
        $this->indexer->index($file->location, $file->path, $store);

        return 'changed';
    }

    /** Groups of files with identical content, as lists of File. @return list<\Illuminate\Support\Collection<int, File>> */
    public function duplicates(): array
    {
        $hashes = File::query()->select('hash')->groupBy('hash')->havingRaw('count(*) > 1')->pluck('hash');

        return $hashes->map(fn ($hash) => File::where('hash', $hash)->get())->all();
    }

    private function place(File $file, StorageLocation $library, bool $keepSource = false): File
    {
        return $this->organizer->organize($file, $library, $keepSource);
    }

    /** Whether the library already holds a present file with this inbox file's content (an earlier kept import). */
    private function inLibrary(StorageLocation $library, FileStore $inboxStore, string $path): bool
    {
        if (!AudioTags::isAudio($path)) {
            return false;
        }

        return File::where('storage_location_id', $library->id)->where('status', File::PRESENT)
            ->where('hash', $inboxStore->hash($path))->exists();
    }

    /**
     * Tracks that no longer have a file: removed, unless another source (a DLNA server, Spotify) still
     * plays them — then only our own stream is unlinked.
     *
     * @param  iterable<int>  $trackIds
     */
    private function releaseTracks(iterable $trackIds): void
    {
        foreach (Track::whereIn('id', $trackIds)->get() as $track) {
            if ($track->trackFiles()->exists()) {
                continue;
            }

            $others = $track->metadata()->whereIn('key', ['dlna_url', 'external_id'])
                ->where(fn ($q) => $q->where('source', '!=', FileIndexer::URL_SOURCE)->orWhereNull('source'))->exists();

            if ($others) {
                Metadata::where('metadatable_type', $track->getMorphClass())->where('metadatable_id', $track->id)
                    ->where('key', 'dlna_url')->where('source', FileIndexer::URL_SOURCE)->delete();

                continue;
            }

            LibraryRemover::track($track, force: true);
        }
    }
}
