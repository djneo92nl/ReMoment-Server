<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token). Tag editor for the server\'s own audio files (docs/api/tag-editor.md); it rewrites the user\'s files, so say what you will change first. Ids: `file_id` of a file (the storage tool lists the files of a track), `album_id` of a library album. Actions: `show` (file_id: the tags the file holds, has_cover, can_undo) or `album` (album_id: every own file with its tags); `update` with file_id (tags: title, artist, album, album_artist, year, track_number, disc_number, genre; `clear` lists fields to remove; a missing field is left alone) or with album_id (`tags` for all its files, `files` = {file_id: {title, track_number, artist, disc_number}} for single ones; files in an inbox are skipped and named); `cover` (file_id or album_id, image_base64 of a JPEG or PNG: embedded in the file or in every file of the album, and used as the album picture), `remove_cover` (file_id); `undo` (file_id or album_id: puts back tags, cover and path of the last edit; refused once the file changed again). `organize` (default true) moves the file to the library layout for its new tags. Inbox files are never edited. Goes through the admin REST API, so its answers and errors are the same.')]
class EditTags extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['show', 'album', 'update', 'cover', 'remove_cover', 'undo'])->required(),
            'file_id' => $schema->integer()->description('one file'),
            'album_id' => $schema->integer()->description('one album: all its own files'),
            'tags' => $schema->object()->description('update: {title, artist, album, album_artist, year, track_number, disc_number, genre}; with album_id they apply to every file'),
            'clear' => $schema->array()->items($schema->string())->description('update: fields to remove'),
            'files' => $schema->object()->description('update with album_id: {file_id: {title, track_number, artist, disc_number}} per file'),
            'organize' => $schema->boolean()->description('update: also move the file to the library layout (default true)'),
            'image_base64' => $schema->string()->description('cover: a JPEG or PNG, base64'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'action' => 'required|in:show,album,update,cover,remove_cover,undo',
            'file_id' => 'nullable|integer', 'album_id' => 'nullable|integer', 'tags' => 'nullable|array', 'clear' => 'nullable|array',
            'files' => 'nullable|array', 'organize' => 'nullable|boolean', 'image_base64' => 'nullable|string',
        ]);

        $file = $data['file_id'] ?? null;
        $album = $data['album_id'] ?? null;
        $need = fn (string $field) => Response::error("invalid (422): {$field} is required.");
        $target = fn (string $suffix) => $file !== null ? "admin/files/{$file}/{$suffix}" : "admin/albums/{$album}/{$suffix}";

        if ($file === null && $album === null) {
            return $need('file_id or album_id');
        }

        return match ($data['action']) {
            'show' => $file !== null ? $this->api('GET', "admin/files/{$file}/tags") : $this->api('GET', "admin/albums/{$album}/tags"),
            'album' => $album !== null ? $this->api('GET', "admin/albums/{$album}/tags") : $need('album_id'),
            'update' => $file !== null
                ? $this->api('PUT', "admin/files/{$file}/tags", $this->filled(($data['tags'] ?? []) + ['clear' => $data['clear'] ?? null, 'organize' => $data['organize'] ?? null]))
                : $this->api('PUT', "admin/albums/{$album}/tags", $this->filled(['tags' => $data['tags'] ?? null, 'clear' => $data['clear'] ?? null, 'files' => $data['files'] ?? null, 'organize' => $data['organize'] ?? null])),
            'cover' => !isset($data['image_base64']) ? $need('image_base64') : $this->api('POST', $target('cover'), ['image_base64' => $data['image_base64']]),
            'remove_cover' => $file !== null ? $this->api('DELETE', "admin/files/{$file}/cover") : $need('file_id'),
            'undo' => $this->api('POST', $target('tags/undo')),
        };
    }
}
