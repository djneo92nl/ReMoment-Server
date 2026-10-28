<?php

namespace App\Http\Controllers\Api;

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
        $required = $creating ? 'required' : 'sometimes';

        return [
            'artist' => [$required, 'string', 'max:255'],
            'title' => [$required, 'string', 'max:255'],
            'medium' => [$required, Rule::in(PhysicalItem::MEDIA)],
            'year' => ['nullable', 'integer', 'min:1000', 'max:2200'],
            'barcode' => ['nullable', 'string', 'max:32'],
            'catalogue_number' => ['nullable', 'string', 'max:64'],
            'condition' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'acquired_on' => ['nullable', 'date'],
            'release_group_mbid' => ['nullable', 'uuid'],
            'release_mbid' => ['nullable', 'uuid'],
        ];
    }

    private function refuseClients(Request $request): ?JsonResponse
    {
        return $request->filled('client')
            ? response()->json(['error' => 'forbidden', 'message' => 'The physical collection is not available to client devices.'], 403)
            : null;
    }
}
