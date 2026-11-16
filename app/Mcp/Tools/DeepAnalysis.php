<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Deep analysis of ONE track on request (docs/api/deep-analysis.md): request (track_id, kinds, default ["sections"]) queues what is not stored yet and answers status queued | already_done | not_available with a time estimate; status (track_id) says per kind none | queued | running | done | failed; remove (track_id, kind) deletes a stored result. Kind `sections` finds where the music changes (the structure service); kind `stems` separates vocals, drums, bass and other (Demucs, minutes of CPU, about 1.5 GB RAM, on its own queue) and stores a loudness lane per stem, the vocal ratio and the instrumental ranges. Kind `stem_audio` does the same separation and also KEEPS the four stems as audio files (MP3 or FLAC, about 6 MB per 4 minutes as MP3) for the apps\' mixer; it fills `stems` too, so ask for it instead of `stems` when the audio is wanted; action `stems` (track_id) lists the kept files as signed URLs with bpm, key and Camelot code (docs/api/stems.md). The result shows in the track details (`sections`; `stems`, `vocal_ratio`, `instrumental`). Goes through the REST API, so its answers and errors are the same.')]
class DeepAnalysis extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['request', 'status', 'remove', 'stems'])->required(),
            'track_id' => $schema->integer()->description('Library track id')->required(),
            'kinds' => $schema->array()->items($schema->string())->description('request: kinds to compute, `sections`, `stems` and/or `stem_audio`, default ["sections"]'),
            'kind' => $schema->string()->description('remove: the kind to delete (`sections`, `stems` or `stem_audio`, which also deletes the kept files), default "sections"'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:request,status,remove,stems',
            'track_id' => 'required|integer', 'kinds' => 'nullable|array', 'kinds.*' => 'string', 'kind' => 'nullable|string',
        ]);
        $id = $data['track_id'];

        return match ($data['action']) {
            'request' => $this->api('POST', "library/tracks/{$id}/deep-analysis", ['kinds' => $data['kinds'] ?? ['sections']]),
            'status' => $this->api('GET', "library/tracks/{$id}/deep-analysis"),
            'stems' => $this->api('GET', "library/tracks/{$id}/stems"),
            'remove' => $this->api('DELETE', 'library/tracks/'.$id.'/deep-analysis/'.($data['kind'] ?? 'sections')),
        };
    }
}
