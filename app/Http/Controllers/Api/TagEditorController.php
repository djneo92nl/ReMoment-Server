<?php

namespace App\Http\Controllers\Api;

use App\Domain\Storage\LibraryFiles;
use App\Domain\Storage\TagEditor;
use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\Media\Album;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin (`admin.token`) tag editor for own files and albums; see docs/api/tag-editor.md. */
class TagEditorController extends Controller
{
    public function __construct(private TagEditor $editor, private LibraryFiles $files) {}

    /** The tags the file holds now, read from the file. */
    public function show(File $file): JsonResponse
    {
        return $this->guard(fn () => $this->fileBody($file));
    }

    /** Changes tags of one file; `''` removes a field, a missing one is left alone. The file is matched again and enrichment is queued. */
    public function update(Request $request, File $file): JsonResponse
    {
        $data = $request->validate($this->tagRules() + $this->clearRules() + ['organize' => ['nullable', 'boolean']]);

        return $this->guard(function () use ($file, $data, $request) {
            $file = $this->files->retagKeepingEmpty($file, $this->tagsOf($data) + $this->clearOf($data), organize: $request->boolean('organize', true));

            return $this->fileBody($file);
        });
    }

    public function undo(File $file): JsonResponse
    {
        return $this->guard(function () use ($file) {
            $file = $this->editor->undo($file);
            $this->files->enrich($file);

            return $this->fileBody($file);
        });
    }

    /** Sets the cover of one file (multipart `image` or `image_base64`). */
    public function setCover(Request $request, File $file): JsonResponse
    {
        return $this->guard(function () use ($request, $file) {
            $file = $this->editor->edit($file, cover: $this->coverOf($request), organize: false);

            return $this->fileBody($file);
        });
    }

    public function removeCover(File $file): JsonResponse
    {
        return $this->guard(fn () => $this->fileBody($this->editor->edit($file, cover: false, organize: false)));
    }

    /** The tags of every own file of the album, for the batch form. */
    public function album(Album $album): JsonResponse
    {
        return $this->guard(fn () => $this->albumBody($album));
    }

    /**
     * Changes every own file of an album: `tags` for all, `files` (file id => title, track_number, artist, disc_number) for single
     * ones. Files that cannot be edited are skipped and listed, a failing one is listed and the rest still go ahead.
     */
    public function updateAlbum(Request $request, Album $album): JsonResponse
    {
        $data = $request->validate([
            'tags' => ['nullable', 'array'], 'files' => ['nullable', 'array'], 'files.*' => ['array'], 'organize' => ['nullable', 'boolean'],
            'clear' => ['nullable', 'array'], 'clear.*' => ['string', Rule::in(TagEditor::FIELDS)],
            ...collect($this->tagRules())->mapWithKeys(fn ($r, $k) => ["tags.{$k}" => $r, "files.*.{$k}" => $r])->all(),
        ]);

        return $this->guard(function () use ($album, $data, $request) {
            $perFile = collect($data['files'] ?? [])->mapWithKeys(fn ($tags, $id) => [(int) $id => $this->tagsOf($tags)])->all();

            return $this->editor->editAlbum($album, $this->tagsOf($data['tags'] ?? []) + $this->clearOf($data), $perFile, organize: $request->boolean('organize', true));
        });
    }

    /** Sets the cover of the album: embedded in each of its own files and used as the album's picture. */
    public function setAlbumCover(Request $request, Album $album): JsonResponse
    {
        return $this->guard(fn () => $this->editor->editAlbum($album, cover: $this->coverOf($request), organize: false));
    }

    public function undoAlbum(Album $album): JsonResponse
    {
        return $this->guard(fn () => $this->editor->undoAlbum($album));
    }

    /** @return array<string, mixed> */
    private function fileBody(File $file): array
    {
        $file->refresh();
        $state = $this->editor->read($file);

        return [
            'id' => $file->id, 'storage_location_id' => $file->storage_location_id, 'path' => $file->path, 'hash' => $file->hash,
            'size' => $file->size, 'mime_type' => $file->mime_type, 'codec' => $file->codec, 'bitrate' => $file->bitrate,
            'sample_rate' => $file->sample_rate, 'bit_depth' => $file->bit_depth, 'duration' => $file->duration, 'status' => $file->status,
            'track_id' => $file->tracks()->value('tracks.id'),
        ] + $state;
    }

    /** @return array<string, mixed> */
    private function albumBody(Album $album): array
    {
        $files = $this->editor->albumFiles($album)->map(function (File $file) {
            $state = $this->editor->read($file);

            return ['file_id' => $file->id, 'path' => $file->path, 'track_id' => $file->tracks()->value('tracks.id')] + $state;
        })->values();

        return [
            'album_id' => $album->id, 'name' => $album->name, 'files' => $files,
            'can_undo' => $files->contains('can_undo', true),
            'editable' => $files->contains('editable', true),
        ];
    }

    /** @return array<string, list<string>> */
    private function tagRules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:500'], 'artist' => ['nullable', 'string', 'max:500'], 'album' => ['nullable', 'string', 'max:500'],
            'album_artist' => ['nullable', 'string', 'max:500'], 'year' => ['nullable', 'string', 'max:10'],
            'track_number' => ['nullable', 'integer', 'min:0'], 'disc_number' => ['nullable', 'integer', 'min:0'], 'genre' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, list<mixed>> `clear`: the fields to remove (an empty string cannot be sent: the API turns it into null) */
    private function clearRules(): array
    {
        return ['clear' => ['nullable', 'array'], 'clear.*' => ['string', Rule::in(TagEditor::FIELDS)]];
    }

    /** @return array<string, string> the fields of `clear`, as the editor wants a removal: an empty string */
    private function clearOf(array $data): array
    {
        return array_fill_keys((array) ($data['clear'] ?? []), '');
    }

    /** @return array<string, mixed> only the editable fields that were sent (a missing field stays missing, '' stays '') */
    private function tagsOf(array $data): array
    {
        return array_intersect_key($data, array_flip(TagEditor::FIELDS));
    }

    /** @return array{data: string, mime: string} */
    private function coverOf(Request $request): array
    {
        $request->validate(['image' => ['nullable', 'file', 'max:10240'], 'image_base64' => ['nullable', 'string']]);

        if ($request->hasFile('image')) {
            return $this->editor->coverFrom((string) file_get_contents($request->file('image')->getRealPath()));
        }
        if ($request->filled('image_base64')) {
            $encoded = (string) preg_replace('#^data:[^,]*,#', '', $request->string('image_base64')->toString());
            $bytes = base64_decode($encoded, true);

            return $this->editor->coverFrom($bytes === false ? '' : $bytes);
        }

        throw new \InvalidArgumentException('Send the picture as `image` (multipart) or `image_base64`.');
    }

    /** An invalid request is 422, a failing disk or ffmpeg 502. */
    private function guard(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'storage_error', 'message' => $e->getMessage()], 502);
        }
    }
}
