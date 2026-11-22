<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Deep analysis of ONE track on request (docs/api/deep-analysis.md): request (track_id, kinds, default ["sections"]) queues what is not stored yet and answers status queued | already_done | not_available with a time estimate; status (track_id) says per kind none | queued | running | done | failed; remove (track_id, kind) deletes a stored result. Kind `sections` finds where the music changes (the structure service); kind `stems` separates vocals, drums, bass and other (Demucs, minutes of CPU, about 1.5 GB RAM, on its own queue) and stores a loudness lane per stem, the vocal ratio and the instrumental ranges. Kind `stem_audio` does the same separation and also KEEPS the four stems as audio files (MP3 or FLAC, about 6 MB per 4 minutes as MP3) for the apps\' mixer; it fills `stems` too, so ask for it instead of `stems` when the audio is wanted; Kind `mood` stores the mood over time (arousal and valence per few seconds, `mood_curve` in the track details) and kind `section_sound` (needs the sections first) stores one sound vector per section, which action `similar_sections` (track_id, optional section index and limit) uses to list sections of OTHER analysed tracks that sound like that section (only tracks that had `section_sound` run can match); both need the Essentia sidecar\'s ML build. action `stems` (track_id) lists the kept files as signed URLs with bpm, key and Camelot code (docs/api/stems.md). The Demucs weights are NOT included and not downloaded by themselves (licence unclear): action `weights` (no track_id) shows state missing | downloading | ready | failed with size, progress, the pinned source and the licence note, and kinds `stems` and `stem_audio` are available only when it is ready; action `download_weights` (ADMIN, needs the admin token; no track_id) starts that opt-in download, which the stems service then does itself (progress via `weights`). The result shows in the track details (`sections`; `stems`, `vocal_ratio`, `instrumental`). Goes through the REST API, so its answers and errors are the same.')]
class DeepAnalysis extends Tool
{
    use CallsApi;

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['request', 'status', 'remove', 'stems', 'similar_sections', 'weights', 'download_weights'])->required(),
            'track_id' => $schema->integer()->description('Library track id (every action but `weights` and `download_weights`)'),
            'kinds' => $schema->array()->items($schema->string())->description('request: kinds to compute, `sections`, `stems`, `stem_audio`, `mood` (mood over time) and/or `section_sound` (needs sections), default ["sections"]'),
            'section' => $schema->integer()->description('similar_sections: the section index (default the first)'),
            'limit' => $schema->integer()->description('similar_sections: how many matches (1-25, default 10)'),
            'kind' => $schema->string()->description('remove: the kind to delete (`sections`, `stems`, `stem_audio`, which also deletes the kept files, `mood` or `section_sound`), default "sections"'),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'action' => 'required|in:request,status,remove,stems,similar_sections,weights,download_weights',
            'track_id' => 'required_unless:action,weights,download_weights|nullable|integer', 'kinds' => 'nullable|array', 'kinds.*' => 'string', 'kind' => 'nullable|string', 'section' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1',
        ]);
        $id = $data['track_id'] ?? null;

        return match ($data['action']) {
            'request' => $this->api('POST', "library/tracks/{$id}/deep-analysis", ['kinds' => $data['kinds'] ?? ['sections']]),
            'status' => $this->api('GET', "library/tracks/{$id}/deep-analysis"),
            'stems' => $this->api('GET', "library/tracks/{$id}/stems"),
            'similar_sections' => $this->api('GET', "library/tracks/{$id}/similar-sections", array_filter(['section' => $data['section'] ?? null, 'limit' => $data['limit'] ?? null], fn ($v) => $v !== null)),
            'weights' => $this->api('GET', 'stems/weights'),
            'download_weights' => $this->api('POST', 'admin/stems/weights'),
            'remove' => $this->api('DELETE', 'library/tracks/'.$id.'/deep-analysis/'.($data['kind'] ?? 'sections')),
        };
    }
}
