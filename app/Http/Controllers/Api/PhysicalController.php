<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalogue\PhysicalImport;
use App\Domain\Catalogue\PhysicalItems;
use App\Http\Controllers\Controller;
use App\Models\Media\PhysicalItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The physical collection: CDs, records and tapes on the shelf (docs/api/physical.md). Not for client devices. */
class PhysicalController extends Controller
{
    public const PAGE_SIZE = 50;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'medium' => ['nullable', Rule::in(PhysicalItem::MEDIA)],
            'q' => ['nullable', 'string', 'max:100'],
            'ripped' => ['nullable', 'boolean'],
            'cursor' => ['nullable', 'string', 'max:50'],
        ]);
        if ($refused = $this->refuseClients($request)) {
            return $refused;
        }

        $offset = max(0, (int) base64_decode((string) $request->query('cursor'), true));
        $items = PhysicalItem::query()
            ->when($request->query('medium'), fn ($q, $m) => $q->where('medium', $m))
            ->when($request->filled('ripped'), fn ($q) => $request->boolean('ripped') ? $q->whereNotNull('ripped_at') : $q->whereNull('ripped_at'))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('artist', 'like', "%{$term}%")->orWhere('title', 'like', "%{$term}%")->orWhere('barcode', 'like', "%{$term}%")))
            ->orderBy('artist_key')->orderBy('title_key')->orderBy('id')
            ->offset($offset)->limit(self::PAGE_SIZE + 1)->get();

        return response()->json([
            'data' => $items->take(self::PAGE_SIZE)->map(fn (PhysicalItem $i) => PhysicalItems::item($i))->values(),
            'next_cursor' => $items->count() > self::PAGE_SIZE ? base64_encode((string) ($offset + self::PAGE_SIZE)) : null,
        ]);
    }

    /** The releases MusicBrainz knows for a barcode, to choose from before adding. */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['barcode' => ['required', 'string', 'regex:/^[0-9 -]{6,20}$/']]);
        if ($refused = $this->refuseClients($request)) {
            return $refused;
        }

        try {
            return response()->json(['data' => PhysicalItems::lookupBarcode((string) $request->query('barcode'))]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'driver_error', 'message' => 'MusicBrainz did not answer: '.$e->getMessage()], 502);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        if ($refused = $this->refuseClients($request)) {
            return $refused;
        }

        return response()->json(PhysicalItems::item(PhysicalItems::add($data)), 201);
    }

    /**
     * CSV import, step one: reads a file (`file`) or pasted text (`csv`), writes nothing and answers what each row would become
     * plus a `token` to confirm with.
     */
    public function importPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['nullable', 'file', 'max:'.(int) (PhysicalImport::MAX_BYTES / 1024)],
            'csv' => ['nullable', 'string'],
            'default_medium' => ['nullable', Rule::in(PhysicalItem::MEDIA)],
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['string', 'max:100'],
            'rows' => ['nullable', 'integer', 'min:0', 'max:'.PhysicalImport::MAX_ROWS],
        ]);
        if ($refused = $this->refuseClients($request)) {
            return $refused;
        }

        $text = $request->hasFile('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : (string) ($data['csv'] ?? '');
        if (trim($text) === '') {
            return response()->json(['error' => 'invalid', 'message' => 'Send the CSV as a `file` or as `csv` text.'], 422);
        }

        try {
            return response()->json(['status' => 'preview'] + PhysicalImport::preview($text, $data['default_medium'] ?? null, $data['mapping'] ?? [], isset($data['rows']) ? (int) $data['rows'] : null));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        }
    }

    /** CSV import, step two: adds what the preview showed (rows are classified again, so a row added meanwhile is skipped). */
    public function importConfirm(Request $request, string $token): JsonResponse
    {
        if ($refused = $this->refuseClients($request)) {
            return $refused;
        }

        $result = PhysicalImport::confirm($token);

        return $result === null
            ? response()->json(['error' => 'not_found', 'message' => 'This import preview was not found or has expired. Upload the file again.'], 404)
            : response()->json(['status' => 'ok'] + $result);
    }

    public function show(Request $request, PhysicalItem $physical): JsonResponse
    {
        if ($refused = $this->refuseClients($request)) {
            return $refused;
        }

        return response()->json(PhysicalItems::item($physical));
    }

    public function update(Request $request, PhysicalItem $physical): JsonResponse
    {
        $data = $request->validate($this->rules(false) + ['ripped' => ['nullable', 'boolean']]);
        if ($refused = $this->refuseClients($request)) {
            return $refused;
        }

        $item = PhysicalItems::update($physical, $data);
        if (array_key_exists('ripped', $data)) {
            $item->ripped_at = $data['ripped'] ? ($item->ripped_at ?? now()) : null;
            $item->save();
        }

        return response()->json(PhysicalItems::item($item));
    }

    public function destroy(Request $request, PhysicalItem $physical): JsonResponse
    {
        if ($refused = $this->refuseClients($request)) {
            return $refused;
        }
        PhysicalItems::remove($physical);

        return response()->json(['status' => 'ok']);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        return PhysicalItems::rules($creating);
    }

    private function refuseClients(Request $request): ?JsonResponse
    {
        return $request->filled('client')
            ? response()->json(['error' => 'forbidden', 'message' => 'The physical collection is not available to client devices.'], 403)
            : null;
    }
}
