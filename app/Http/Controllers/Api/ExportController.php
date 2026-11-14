<?php

namespace App\Http\Controllers\Api;

use App\Domain\Export\ExportRefused;
use App\Domain\Export\ExportService;
use App\Domain\Library\IdAliases;
use App\Http\Controllers\Controller;
use App\Models\Export;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Export from the library (workstream 13, step 3): estimate, start, poll, signed download and cancel/remove of
 * an export, admin token only (the media is open to the household, an export is a heavy operation).
 * See docs/api/export.md and docs/architecture/export.md.
 */
class ExportController extends Controller
{
    /** Seconds a signed download URL stays valid; every GET of the export makes a fresh one. */
    public const URL_TTL = ExportService::URL_TTL;

    public function __construct(private readonly ExportService $exports) {}

    public function estimate(Request $request): JsonResponse
    {
        return response()->json($this->exports->estimate($this->selection($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $draft = $this->selection($request);

        try {
            $export = $this->exports->start($draft);
        } catch (ExportRefused $e) {
            return response()->json(['error' => $e->errorCode, 'message' => $e->getMessage()] + ($e->estimate ? ['estimate' => $e->estimate] : []), $e->status);
        }

        return response()->json(['id' => $export->public_id, 'status' => $export->status, 'estimate' => $this->exports->estimate($export)], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 20)));

        return response()->json(['data' => Export::query()->latest('id')->limit($limit)->get()->map(fn (Export $e) => $this->exports->resource($e))->all()]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->exports->resource($this->find($id)));
    }

    /** The signed, temporary download: the signature is the authorization, no token header needed. */
    public function download(string $id, int $part): BinaryFileResponse|JsonResponse
    {
        $export = $this->find($id);
        $file = $export->status === Export::DONE ? ($export->part_files[$part - 1] ?? null) : null;
        $disk = Storage::disk(config('export.disk'));
        $path = $file ? $export->directory().'/'.$file['name'] : null;

        if (!$file || !$disk->exists($path)) {
            return response()->json(['error' => 'not_found', 'message' => 'This export part is not available (not finished, expired or removed).'], 404);
        }

        // BinaryFileResponse answers Range requests (resumable downloads) by itself.
        return response()->download($disk->path($path), $file['name'], ['Content-Type' => 'application/zip']);
    }

    /** Cancels a queued or running export, or removes a finished one with its files. */
    public function destroy(string $id): JsonResponse
    {
        $export = $this->find($id);

        return response()->json(['status' => 'ok', 'id' => $export->public_id, 'result' => $this->exports->cancel($export)]);
    }

    private function find(string $id): Export
    {
        return Export::query()->where('public_id', $id)->firstOrFail();
    }

    /** Validates the body and returns an unsaved Export holding the selection (alias ids resolved). */
    private function selection(Request $request): Export
    {
        $aliases = app(IdAliases::class);
        $type = $request->input('type');
        if (in_array($type, ['album', 'playlist', 'artist'], true)) {
            $aliases->resolveInput($request, ['id' => $type]);
        }
        if ($type === 'tracks' && is_array($request->input('track_ids'))) {
            $request->merge(['track_ids' => array_map(
                fn ($id) => is_numeric($id) && (int) $id == $id && (int) $id > 0 ? $aliases->resolve('track', (int) $id) : $id,
                $request->input('track_ids'),
            )]);
        }

        $table = ['album' => 'albums', 'playlist' => 'playlists', 'artist' => 'artists'][$type] ?? null;
        $data = $request->validate([
            'type' => ['required', Rule::in(['album', 'playlist', 'artist', 'tracks'])],
            'id' => $table ? ['required', 'integer', Rule::exists($table, 'id')] : ['nullable'],
            'track_ids' => $type === 'tracks' ? ['required', 'array', 'min:1', 'max:5000'] : ['nullable'],
            'track_ids.*' => ['integer', Rule::exists('tracks', 'id')],
            'profile' => ['nullable', Rule::in(array_keys(config('export.profiles')))],
            'options' => ['nullable', 'array'],
            'options.layout' => ['nullable', Rule::in(['tree', 'flat'])],
        ]);

        return $this->exports->draft($data['type'], $table ? (int) $data['id'] : null, $data['track_ids'] ?? null, $data['profile'] ?? null, $data['options'] ?? null);
    }
}
