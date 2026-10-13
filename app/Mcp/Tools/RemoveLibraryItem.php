<?php

namespace App\Mcp\Tools;

use App\Domain\Library\LibraryRemover;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('ADMIN (needs the admin token), DESTRUCTIVE. Remove a track, album or artist (with their records) from the library, as the web UI does. Requires confirm=true; on a track, `force` also removes it when it has own files. See what is removed first via browse_library.')]
#[IsDestructive]
class RemoveLibraryItem extends Tool
{
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['track', 'album', 'artist'])->required(),
            'id' => $schema->integer()->required(),
            'confirm' => $schema->boolean()->description('Must be true')->required(),
            'force' => $schema->boolean()->description('track: remove although it has files'),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate(['type' => 'required|in:track,album,artist', 'id' => 'required|integer', 'confirm' => 'required|boolean', 'force' => 'nullable|boolean']);

        if (!$data['confirm']) {
            return Response::error('confirmation_required (422): call again with confirm=true.');
        }

        $model = match ($data['type']) {
            'track' => Track::find($data['id']),
            'album' => Album::find($data['id']),
            'artist' => Artist::find($data['id']),
        };

        if (!$model) {
            return Response::error('not_found (404): nothing with that id.');
        }

        $result = match ($data['type']) {
            'track' => LibraryRemover::track($model, (bool) ($data['force'] ?? false)),
            'album' => LibraryRemover::album($model),
            'artist' => LibraryRemover::artist($model),
        };

        if ($result === false || $result === null) {
            return Response::error("kept (409): this {$data['type']} is still available from DLNA, so it would come back at the next scan"
                .($data['type'] === 'track' ? '; pass force=true to remove it anyway.' : '.'));
        }

        return Response::json(['status' => 'ok', 'type' => $data['type'], 'id' => $data['id'], 'removed' => $result]);
    }
}
