<?php

namespace App\Domain\Export;

use App\Domain\Analysis\Tools;
use App\Domain\Library\IdAliases;
use App\Jobs\ExportLibrarySelection;
use App\Models\Export;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The one implementation of the export operations (workstream 13): estimate, start, cancel/remove and the
 * resource shape. The REST controller (docs/api/export.md) and the web dialog (Livewire\ExportDialog) both
 * call it, so the rules and the answers cannot drift apart.
 */
class ExportService
{
    /** Seconds a signed download URL stays valid; every read of the export makes a fresh one. */
    public const URL_TTL = 900;

    public function __construct(private readonly ExportPlanner $planner) {}

    /**
     * An unsaved Export holding a validated selection (alias ids resolved to the surviving record).
     *
     * @param  list<int>|null  $trackIds
     * @param  array<string, mixed>|null  $options
     */
    public function draft(string $type, ?int $id, ?array $trackIds, ?string $profile, ?array $options = null): Export
    {
        $aliases = app(IdAliases::class);
        $entity = ['album' => 'album', 'playlist' => 'playlist', 'artist' => 'artist'][$type] ?? null;

        return new Export([
            'selection_type' => $type,
            'selection_id' => $entity && $id ? $aliases->resolve($entity, $id) : null,
            'track_ids' => $type === 'tracks' ? array_values(array_unique(array_map('intval', $trackIds ?? []))) : null,
            'profile' => $profile ?: config('export.default_profile'),
            'options' => array_filter(['layout' => $options['layout'] ?? null]) ?: null,
        ]);
    }

    public function plan(Export $draft): ExportPlan
    {
        return $this->planner->plan(ExportSelection::tracks($draft), $draft->profile);
    }

    /** @return array<string, mixed> */
    public function estimate(Export $draft, ?ExportPlan $plan = null): array
    {
        $plan ??= $this->plan($draft);

        return [
            'title' => ExportSelection::title($draft),
            'selection' => $this->selectionBody($draft),
            'totals' => $plan->totals(),
            'skipped' => $this->skipped($draft, $plan),
            'limits' => ['max_gb' => (float) config('export.max_gb'), 'part_gb' => (float) config('export.part_gb'), 'dir_max_gb' => (float) config('export.dir_max_gb')],
        ];
    }

    /**
     * Saves the draft as a queued export and dispatches the job.
     *
     * @throws ExportRefused over the limits, nothing to export, or ffmpeg missing
     */
    public function start(Export $draft): Export
    {
        $plan = $this->plan($draft);
        $estimate = $this->estimate($draft, $plan);

        if ($plan->exceedsLimits()) {
            throw new ExportRefused('export_too_large', 'The export is over the limit ('.implode(', ', $plan->exceeds).'): about '.round($plan->estimatedBytes / 1024 ** 3, 2).' GB. Choose less, or a lower profile.', 422, $estimate);
        }
        if ($plan->exportable() === 0) {
            throw new ExportRefused('nothing_to_export', 'None of the tracks has an own file on this server, so there is nothing to export.', 422, $estimate);
        }
        if (!Tools::available(Tools::ffmpeg())) {
            throw new ExportRefused('not_available', 'ffmpeg is not available on this server, so an export cannot run.', 409);
        }

        $draft->forceFill([
            'status' => Export::QUEUED, 'tracks' => $plan->tracks, 'copied' => $plan->copied, 'transcoded' => $plan->transcoded,
            'skipped_count' => $plan->skipped, 'estimated_bytes' => $plan->estimatedBytes, 'planned_parts' => $plan->parts,
            'files_total' => $plan->exportable(), 'files_done' => 0, 'skipped' => $this->skipped($draft, $plan),
        ])->save();
        ExportLibrarySelection::dispatch($draft);

        return $draft;
    }

    /** Cancels a queued or running export, or removes a finished one with its files: 'cancelled' | 'removed'. */
    public function cancel(Export $export): string
    {
        $status = $export->status;

        // A running job notices that its row is gone at the next file and removes its own output.
        $export->delete();
        if ($status !== Export::RUNNING) {
            Storage::disk(config('export.disk'))->deleteDirectory($export->directory());
        }

        return in_array($status, [Export::QUEUED, Export::RUNNING], true) ? 'cancelled' : 'removed';
    }

    /** @return list<array{track_id: int, name: ?string, reason: ?string}> */
    public function skipped(Export $export, ExportPlan $plan): array
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
    public function selectionBody(Export $export): array
    {
        return ['type' => $export->selection_type, 'id' => $export->selection_id] + ($export->selection_type === 'tracks' ? ['track_ids' => $export->track_ids] : []);
    }

    /** @return array<string, mixed> */
    public function resource(Export $e): array
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
