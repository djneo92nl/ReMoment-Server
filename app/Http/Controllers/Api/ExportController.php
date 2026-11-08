<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analysis\Tools;
use App\Domain\Export\ExportPlan;
use App\Domain\Export\ExportPlanner;
use App\Domain\Export\ExportSelection;
use App\Domain\Library\IdAliases;
use App\Http\Controllers\Controller;
use App\Jobs\ExportLibrarySelection;
use App\Models\Export;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
    public const URL_TTL = 900;

    public function estimate(Request $request, ExportPlanner $planner): JsonResponse
    {
        $export = $this->selection($request);
        $plan = $planner->plan(ExportSelection::tracks($export), $export->profile);

        return response()->json($this->estimateBody($export, $plan));
    }

    public function store(Request $request, ExportPlanner $planner): JsonResponse
    {
        $export = $this->selection($request);
        $plan = $planner->plan(ExportSelection::tracks($export), $export->profile);
        $estimate = $this->estimateBody($export, $plan);

        if ($plan->exceedsLimits()) {
            return response()->json([
                'error' => 'export_too_large',
                'message' => 'The export is over the limit ('.implode(', ', $plan->exceeds).'): about '.round($plan->estimatedBytes / 1024 ** 3, 2).' GB. Choose less, or a lower profile.',
                'estimate' => $estimate,
            ], 422);
        }
        if ($plan->exportable() === 0) {
            return response()->json([
                'error' => 'nothing_to_export',
                'message' => 'None of the tracks has an own file on this server, so there is nothing to export.',
                'estimate' => $estimate,
            ], 422);
        }
        if (!Tools::available(Tools::ffmpeg())) {
            return response()->json(['error' => 'not_available', 'message' => 'ffmpeg is not available on this server, so an export cannot run.'], 409);
        }

        $export->forceFill([
            'status' => Export::QUEUED, 'tracks' => $plan->tracks, 'copied' => $plan->copied, 'transcoded' => $plan->transcoded,
            'skipped_count' => $plan->skipped, 'estimated_bytes' => $plan->estimatedBytes, 'planned_parts' => $plan->parts,
            'files_total' => $plan->exportable(), 'files_done' => 0, 'skipped' => $this->skipped($export, $plan),
        ])->save();
        ExportLibrarySelection::dispatch($export);

        return response()->json(['id' => $export->public_id, 'status' => $export->status, 'estimate' => $estimate], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 20)));

        return response()->json(['data' => Export::query()->latest('id')->limit($limit)->get()->map(fn (Export $e) => $this->resource($e))->all()]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->resource($this->find($id)));
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
        $status = $export->status;

        // A running job notices that its row is gone at the next file and removes its own output.
        $export->delete();
        if ($status !== Export::RUNNING) {
            Storage::disk(config('export.disk'))->deleteDirectory($export->directory());
        }

        return response()->json([
            'status' => 'ok', 'id' => $export->public_id,
            'result' => in_array($status, [Export::QUEUED, Export::RUNNING], true) ? 'cancelled' : 'removed',
        ]);
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

        return new Export([
            'selection_type' => $data['type'],
            'selection_id' => $table ? (int) $data['id'] : null,
            'track_ids' => $type === 'tracks' ? array_values(array_unique(array_map('intval', $data['track_ids']))) : null,
            'profile' => $data['profile'] ?? config('export.default_profile'),
            'options' => array_filter(['layout' => $data['options']['layout'] ?? null]) ?: null,
        ]);
    }

    /** @return list<array{track_id: int, name: ?string, reason: ?string}> */
    private function skipped(Export $export, ExportPlan $plan): array
    {
        $names = ExportSelection::tracks($export)->pluck('name', 'id');
        $skipped = [];
        foreach ($plan->items as $item) {
            if ($item->action === ExportPlanner::SKIP) {
                $skipped[] = ['track_id' => $item->trackId, 'name' => $names[$item->trackId] ?? null, 'reason' => $item->reason];
            }
        }

        return $skipped;
    }

    /** @return array<string, mixed> */
    private function estimateBody(Export $export, ExportPlan $plan): array
    {
        return [
            'title' => ExportSelection::title($export),
            'selection' => $this->selectionBody($export),
            'totals' => $plan->totals(),
            'skipped' => $this->skipped($export, $plan),
            'limits' => ['max_gb' => (float) config('export.max_gb'), 'part_gb' => (float) config('export.part_gb'), 'dir_max_gb' => (float) config('export.dir_max_gb')],
        ];
    }

    /** @return array<string, mixed> */
    private function selectionBody(Export $export): array
    {
        return ['type' => $export->selection_type, 'id' => $export->selection_id] + ($export->selection_type === 'tracks' ? ['track_ids' => $export->track_ids] : []);
    }

    /** @return array<string, mixed> */
    private function resource(Export $e): array
    {
        $done = $e->status === Export::DONE;
        $parts = [];
        foreach ($done ? ($e->part_files ?? []) : [] as $i => $part) {
            $parts[] = $part + ['part' => $i + 1, 'url' => URL::temporarySignedRoute('api.library.exports.download', now()->addSeconds(self::URL_TTL), ['id' => $e->public_id, 'part' => $i + 1], absolute: false)];
        }
        $total = (int) $e->files_total;

        return [
            'id' => $e->public_id,
            'status' => $e->status,
            'title' => ExportSelection::title($e),
            'selection' => $this->selectionBody($e),
            'profile' => $e->profile,
            'options' => $e->options ?? (object) [],
            'progress' => ['files_done' => (int) $e->files_done, 'files_total' => $total, 'percent' => $done ? 100 : ($total > 0 ? (int) floor($e->files_done / $total * 100) : 0)],
            'totals' => [
                'tracks' => $e->tracks, 'copied' => $e->copied, 'transcoded' => $e->transcoded, 'skipped' => $e->skipped_count,
                'estimated_bytes' => $e->estimated_bytes, 'planned_parts' => $e->planned_parts,
            ],
            'skipped' => $e->skipped ?? [],
            'parts' => $parts,
            'bytes' => $e->bytes,
            'error' => $e->error,
            'created_at' => $e->created_at?->toIso8601String(),
            'started_at' => $e->started_at?->toIso8601String(),
            'finished_at' => $e->finished_at?->toIso8601String(),
            'expires_at' => $e->expires_at?->toIso8601String(),
        ];
    }
}
