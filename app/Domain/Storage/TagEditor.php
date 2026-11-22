<?php

namespace App\Domain\Storage;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LocalCover;
use App\Domain\Library\LibraryRemover;
use App\Jobs\ProcessArtwork;
use App\Models\File;
use App\Models\FileTagSnapshot;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\StorageLocation;
use App\Models\TrackFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The tag editor for own files (workstream 8.4, docs/architecture/tag-editor.md): the one place that writes tags, a cover
 * or a new name into a file of the library. The API, the web pages, `LibraryFiles::retag` and anything that accepts a
 * suggestion go through it.
 *
 * A write is safe: the new version is made as a copy (TagWriter, ffmpeg `-c copy`, read back with getID3) and renamed over
 * the original, so a failure leaves the file as it was. Files in an `inbox` location are never touched (the inbox is often
 * the user's own music, mounted read-only). After the write the file is indexed again (hash, size, tags, track identity
 * through LibraryIdentity), laid out by the organizer when asked, and the analysis of the audio is carried over to the new
 * hash, because the audio did not change. The last edit of each file can be undone from a stored snapshot.
 */
class TagEditor
{
    /** Editable fields, in the order forms show them. */
    public const FIELDS = ['title', 'artist', 'album', 'album_artist', 'year', 'track_number', 'disc_number', 'genre'];

    /** Largest cover accepted, bytes. */
    public const MAX_COVER_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private FileIndexer $indexer,
        private LibraryOrganizer $organizer,
        private LibraryFiles $files,
    ) {}

    /**
     * The tags the file holds now (read from the file, not from the library), whether it has a cover and whether its last
     * edit can be undone.
     *
     * @return array{tags: array<string, string>, has_cover: bool, can_undo: bool, editable: bool, cover_editable: bool}
     */
    public function read(File $file): array
    {
        $read = (new FileStore($file->location))->withLocalPath($file->path, fn (string $local) => AudioTags::read($local)) ?? [];

        return [
            'tags' => array_combine(self::FIELDS, array_map(fn (string $f) => TagWriter::fieldValue($read, $f) ?? '', self::FIELDS)),
            'has_cover' => !empty($read['picture']['data']),
            'can_undo' => $this->canUndo($file),
            'editable' => $this->editable($file),
            'cover_editable' => $this->editable($file) && TagWriter::supportsCover($file->extension),
        ];
    }

    /** The embedded cover of the file, for a preview; null when it has none. @return array{data: string, mime: ?string}|null */
    public function cover(File $file): ?array
    {
        $read = (new FileStore($file->location))->withLocalPath($file->path, fn (string $local) => AudioTags::read($local)) ?? [];

        return $read['picture'] ?? null;
    }

    public function editable(File $file): bool
    {
        return $file->location?->role === StorageLocation::ROLE_LIBRARY && TagWriter::supports($file->extension) && TagWriter::available();
    }

    public function canUndo(File $file): bool
    {
        $snapshot = FileTagSnapshot::where('file_id', $file->id)->first();

        return $snapshot !== null && $snapshot->hash_after === $file->hash;
    }

    /**
     * Changes tags and/or the cover of one file.
     *
     * @param  array<string, string|int|null>  $tags  field => value (see FIELDS); null or missing leaves it, '' removes it
     * @param  array{data: string, mime: ?string}|false|null  $cover  null keeps the picture, false removes it, an array replaces it
     * @param  bool  $organize  also move the file to the library layout for its new tags (a new name and folder)
     *
     * @throws \InvalidArgumentException the request cannot be done (inbox file, unsupported format, nothing to change, bad cover)
     * @throws \RuntimeException the write failed; the file is as it was
     */
    public function edit(File $file, array $tags = [], array|false|null $cover = null, bool $organize = true): File
    {
        $tags = $this->clean($tags);
        $cover = $this->checkCover($cover);
        $this->guard($file, $cover !== null);

        if ($tags === [] && $cover === null) {
            throw new \InvalidArgumentException('Nothing to change.');
        }

        $store = new FileStore($file->location);
        $read = $store->withLocalPath($file->path, fn (string $local) => AudioTags::read($local)) ?? throw new \InvalidArgumentException('The file could not be read as audio.');

        $before = ['tags' => array_combine(self::FIELDS, array_map(fn (string $f) => TagWriter::fieldValue($read, $f), self::FIELDS)),
            'cover' => $read['picture'] ?? null, 'path' => $file->path];

        $updated = $this->apply($file, $tags, $cover, $organize);

        $this->remember($updated, $before, $cover !== null);

        return $updated;
    }

    /** Puts the file back as it was before its last edit: tags, cover and path. */
    public function undo(File $file): File
    {
        $snapshot = FileTagSnapshot::where('file_id', $file->id)->first() ?? throw new \InvalidArgumentException('There is no edit of this file to undo.');
        $this->guard($file, false);

        if ($snapshot->hash_after !== $file->hash) {
            throw new \InvalidArgumentException('The file changed after its last edit, so the edit can no longer be undone.');
        }

        $cover = null;
        if ($snapshot->cover_changed) {
            $bytes = $snapshot->had_cover ? Storage::disk('local')->get($snapshot->coverPath()) : null;
            if ($snapshot->had_cover && $bytes === null) {
                throw new \InvalidArgumentException('The previous cover is gone, so the edit can no longer be undone.');
            }
            $cover = $snapshot->had_cover ? ['data' => $bytes, 'mime' => $this->mimeOf($bytes)] : false;
        }

        $tags = array_map(fn ($v) => $v ?? '', (array) $snapshot->tags);
        $updated = $this->apply($file, $tags, $cover, organize: false);

        if ($updated->path !== $snapshot->path_before && !(new FileStore($updated->location))->exists($snapshot->path_before)) {
            $updated = $this->organizer->relocate($updated, $updated->location, $snapshot->path_before);
        }

        $this->forget($snapshot);

        return $updated;
    }

    /**
     * Changes every own file of an album: `$tags` apply to all (album, album_artist, artist, year, genre, disc_number),
     * `$perFile` (file id => title, track_number, artist, disc_number) to single files, `$cover` is embedded in each file and
     * becomes the album's picture. A file that cannot be edited (inbox, unsupported format) is skipped and named; one that
     * fails is named and the others still go ahead, each with its own undo.
     *
     * @param  array<string, string|int|null>  $tags
     * @param  array<int, array<string, string|int|null>>  $perFile
     * @param  array{data: string, mime: ?string}|false|null  $cover
     * @return array{album_id: ?int, edited: list<int>, skipped: list<array{file_id: int, reason: string}>, failed: list<array{file_id: int, error: string}>}
     */
    public function editAlbum(Album $album, array $tags = [], array $perFile = [], array|false|null $cover = null, bool $organize = true): array
    {
        $cover = $this->checkCover($cover);
        $files = $this->albumFiles($album);
        if ($files->isEmpty()) {
            throw new \InvalidArgumentException('This album has none of your own files.');
        }
        if ($this->clean($tags) === [] && $cover === null && array_filter($perFile) === []) {
            throw new \InvalidArgumentException('Nothing to change.');
        }

        $result = ['album_id' => $album->id, 'edited' => [], 'skipped' => [], 'failed' => []];
        $last = null;

        foreach ($files as $file) {
            if (!$this->editable($file)) {
                $result['skipped'][] = ['file_id' => $file->id, 'reason' => $file->location?->role === StorageLocation::ROLE_INBOX ? 'inbox' : 'unsupported'];

                continue;
            }

            $fileTags = array_merge($tags, $perFile[$file->id] ?? []);
            if ($this->clean($fileTags) === [] && $cover === null) {
                continue; // nothing for this file
            }

            try {
                $last = $this->edit($file, $fileTags, $cover, $organize);
                $this->files->enrich($last);
                $result['edited'][] = $file->id;
            } catch (\Throwable $e) {
                $result['failed'][] = ['file_id' => $file->id, 'error' => $e->getMessage()];
            }
        }

        // The album is now what the files say: its name may have changed, so it may be another record.
        $current = $last?->tracks()->first()?->album ?? $album->fresh();
        $result['album_id'] = $current?->id ?? $album->id;

        if (is_array($cover) && $current !== null && $result['edited'] !== []) {
            $this->setAlbumPicture($current, $cover);
        }

        return $result;
    }

    /**
     * Undoes the last edit of every file of the album that has one.
     *
     * @return array{album_id: int, undone: list<int>, failed: list<array{file_id: int, error: string}>}
     */
    public function undoAlbum(Album $album): array
    {
        $result = ['album_id' => $album->id, 'undone' => [], 'failed' => []];
        $files = $this->albumFiles($album);
        $last = null;

        // Files may have left this album by their edit: also those whose snapshot points here by the track they hold now.
        foreach ($files as $file) {
            if (!FileTagSnapshot::where('file_id', $file->id)->exists()) {
                continue;
            }
            try {
                $last = $this->undo($file);
                $this->files->enrich($last);
                $result['undone'][] = $file->id;
            } catch (\Throwable $e) {
                $result['failed'][] = ['file_id' => $file->id, 'error' => $e->getMessage()];
            }
        }

        if ($result['undone'] === [] && $result['failed'] === []) {
            throw new \InvalidArgumentException('There is no edit of this album to undo.');
        }

        $result['album_id'] = $last?->tracks()->first()?->album_id ?? $album->id;

        return $result;
    }

    /** Whether any file of the album has an edit to undo. */
    public function albumCanUndo(Album $album): bool
    {
        return FileTagSnapshot::whereIn('file_id', $this->albumFiles($album)->pluck('id'))->exists();
    }

    /** The own files of an album: the original of each of its tracks that is on disk. @return \Illuminate\Database\Eloquent\Collection<int, File> */
    public function albumFiles(Album $album)
    {
        return File::query()
            ->whereIn('id', TrackFile::query()->where('role', TrackFile::ORIGINAL)->whereIn('track_id', $album->tracks()->select('tracks.id'))->select('file_id'))
            ->where('status', File::PRESENT)
            ->with('location')
            ->orderBy('path')
            ->get();
    }

    /** Makes a picture the album's (the artwork pipeline reads it from the private disk). */
    public function setAlbumPicture(Album $album, array $cover): void
    {
        $url = LocalCover::store($cover['data']);
        if ($url === null) {
            return;
        }

        $album->update(['images' => [['url' => $url]]]);
        if (!ArtworkCache::has($url)) {
            ProcessArtwork::dispatch($url);
        }
    }

    /** A cover from an upload or base64 data, checked: a JPEG or PNG picture of at least 100 px, at most 10 MB. @return array{data: string, mime: string} */
    public function coverFrom(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_COVER_BYTES) {
            throw new \InvalidArgumentException('The cover must be a picture of at most 10 MB.');
        }

        $size = @getimagesizefromstring($bytes);
        if ($size === false || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new \InvalidArgumentException('The cover must be a JPEG or PNG picture.');
        }
        if (min($size[0], $size[1]) < 100) {
            throw new \InvalidArgumentException('The cover is too small (at least 100 pixels on the short side).');
        }

        return ['data' => $bytes, 'mime' => $size['mime']];
    }

    /** Writes, then indexes again and (when asked) lays the file out; the part shared by an edit and an undo. */
    private function apply(File $file, array $tags, array|false|null $cover, bool $organize): File
    {
        $store = new FileStore($file->location);
        $location = $file->location;
        $oldHash = $file->hash;
        $oldTrackIds = $file->trackFiles()->pluck('track_id')->all();
        $oldTracks = Track::whereIn('id', $oldTrackIds)->get(['id', 'album_id', 'artist_id']);
        $writer = new TagWriter;

        $store->rewrite($file->path, fn (string $source, string $output) => $writer->write($source, $output, $tags, $cover));

        [$indexed] = $this->indexer->index($location, $file->path, $store);
        if ($indexed === null) {
            throw new \RuntimeException('The file was written but could not be read back as audio.');
        }

        $this->carryAnalysis($indexed, $oldHash);
        $this->files->releaseTracks(array_diff($oldTrackIds, $indexed->trackFiles()->pluck('track_id')->all()));
        $this->pruneEmpty($oldTracks);

        if ($organize && $location->role === StorageLocation::ROLE_LIBRARY) {
            $indexed = $this->organizer->organize($indexed, $location);
        }

        return $indexed->refresh();
    }

    /**
     * A new album or artist name moves the track to another record (LibraryIdentity), which leaves the old album or artist
     * without tracks: they go, as they do when their last track is removed.
     *
     * @param  \Illuminate\Support\Collection<int, Track>  $oldTracks
     */
    private function pruneEmpty($oldTracks): void
    {
        foreach ($oldTracks->pluck('album_id')->filter()->unique() as $id) {
            $album = Album::find($id);
            if ($album !== null && !$album->tracks()->exists()) {
                LibraryRemover::album($album);
            }
        }

        foreach ($oldTracks->pluck('artist_id')->filter()->unique() as $id) {
            $artist = Artist::find($id);
            if ($artist !== null && !$artist->tracks()->exists() && !$artist->albums()->exists()) {
                LibraryRemover::artist($artist);
            }
        }
    }

    /** Keeps the snapshot of the state before this edit (replacing an older one) so it can be undone. */
    private function remember(File $file, array $before, bool $coverChanged): void
    {
        $hadCover = $before['cover'] !== null && !empty($before['cover']['data']);

        $snapshot = FileTagSnapshot::updateOrCreate(['file_id' => $file->id], [
            'tags' => $before['tags'], 'had_cover' => $hadCover, 'cover_changed' => $coverChanged,
            'path_before' => $before['path'], 'hash_after' => $file->hash,
        ]);

        $disk = Storage::disk('local');
        if ($hadCover && $coverChanged) {
            $disk->put($snapshot->coverPath(), $before['cover']['data']);
        } else {
            $disk->delete($snapshot->coverPath());
        }
    }

    private function forget(FileTagSnapshot $snapshot): void
    {
        Storage::disk('local')->delete($snapshot->coverPath());
        $snapshot->delete();
    }

    /**
     * The audio did not change, so what was derived from it stays valid: the analysis, the sound embeddings and the section
     * embeddings follow the file to its new hash instead of being computed again.
     */
    private function carryAnalysis(File $file, string $oldHash): void
    {
        if ($file->hash === $oldHash) {
            return;
        }

        foreach (['file_analyses', 'file_embeddings', 'section_embeddings'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'content_hash')) {
                DB::table($table)->where('file_id', $file->id)->where('content_hash', $oldHash)->update(['content_hash' => $file->hash]);
            }
        }
    }

    private function guard(File $file, bool $withCover): void
    {
        if ($file->location?->role === StorageLocation::ROLE_INBOX) {
            throw new \InvalidArgumentException('Files in the inbox are never edited: import them into the library first.');
        }
        if (!TagWriter::supports($file->extension)) {
            throw new \InvalidArgumentException("Writing tags to .{$file->extension} files is not supported.");
        }
        if ($withCover && !TagWriter::supportsCover($file->extension)) {
            throw new \InvalidArgumentException("A cover cannot be written into .{$file->extension} files.");
        }
        if (!TagWriter::available()) {
            throw new \RuntimeException('Writing tags needs ffmpeg, which is not available on this server.');
        }
        if ($file->status !== File::PRESENT) {
            throw new \InvalidArgumentException('The file is missing from its location.');
        }
    }

    /**
     * Keeps the editable fields, trims them, and turns a number of 0 into "remove".
     *
     * @return array<string, string>
     */
    private function clean(array $tags): array
    {
        $clean = [];

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $tags) || $tags[$field] === null) {
                continue;
            }

            $value = trim((string) $tags[$field]);
            if (in_array($field, ['track_number', 'disc_number'], true)) {
                if ($value !== '' && !ctype_digit($value)) {
                    throw new \InvalidArgumentException("The {$field} must be a number.");
                }
                $value = (int) $value === 0 ? '' : (string) (int) $value;
            }
            if ($field === 'year' && $value !== '' && !preg_match('/^\d{4}([-\/.]\d{1,2}([-\/.]\d{1,2})?)?$/', $value)) {
                throw new \InvalidArgumentException('The year must be four digits (or a date).');
            }

            $clean[$field] = $value;
        }

        return $clean;
    }

    private function checkCover(array|false|null $cover): array|false|null
    {
        if (!is_array($cover)) {
            return $cover;
        }

        return $this->coverFrom((string) ($cover['data'] ?? ''));
    }

    private function mimeOf(string $bytes): ?string
    {
        $size = @getimagesizefromstring($bytes);

        return $size === false ? null : $size['mime'];
    }
}
