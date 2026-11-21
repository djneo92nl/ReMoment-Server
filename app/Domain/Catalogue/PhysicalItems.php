<?php

namespace App\Domain\Catalogue;

use App\Models\Media\Album;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\PhysicalItem;
use App\Services\MusicBrainz\MusicBrainzClient;
use Illuminate\Validation\Rule;

/**
 * The physical collection (workstream 6): items by barcode or by hand, tied to the library album and the MusicBrainz
 * release-group when there is one. An item makes the album **owned** (the `physical` provider), which closes a wish.
 */
final class PhysicalItems
{
    public const FIELDS = ['artist', 'title', 'year', 'medium', 'barcode', 'catalogue_number', 'condition', 'notes', 'location', 'acquired_on', 'release_group_mbid', 'release_mbid'];

    /**
     * Releases MusicBrainz knows for a barcode, to choose from. A rate limit or outage throws.
     *
     * @return list<array{release_mbid: string, release_group_mbid: ?string, artist: string, title: string, year: ?int, label: ?string, catalogue_number: ?string, country: ?string, medium: string}>
     */
    public static function lookupBarcode(string $barcode, ?MusicBrainzClient $mb = null): array
    {
        $digits = preg_replace('/\D/', '', $barcode);
        if ($digits === '') {
            return [];
        }

        $data = ($mb ?? new MusicBrainzClient)->get('release', ['query' => 'barcode:'.$digits, 'limit' => 10]);
        $found = [];

        foreach ($data['releases'] ?? [] as $release) {
            if (empty($release['id'])) {
                continue;
            }
            $credit = $release['artist-credit'] ?? [];
            $info = $release['label-info'][0] ?? [];
            $format = strtolower((string) ($release['media'][0]['format'] ?? ''));

            $found[] = [
                'release_mbid' => $release['id'],
                'release_group_mbid' => $release['release-group']['id'] ?? null,
                'artist' => trim(implode('', array_map(fn ($c) => ($c['name'] ?? $c['artist']['name'] ?? '').($c['joinphrase'] ?? ''), $credit))),
                'title' => (string) ($release['title'] ?? ''),
                'year' => isset($release['date']) && preg_match('/^\d{4}/', $release['date']) ? (int) substr($release['date'], 0, 4) : null,
                'label' => $info['label']['name'] ?? null,
                'catalogue_number' => $info['catalog-number'] ?? null,
                'country' => $release['country'] ?? null,
                'medium' => str_contains($format, 'vinyl') ? 'vinyl' : (str_contains($format, 'cassette') ? 'tape' : (str_contains($format, 'minidisc') ? 'minidisc' : (str_contains($format, 'cd') || $format === '' ? 'cd' : 'other'))),
            ];
        }

        return $found;
    }

    /** Validation rules of an item's fields (the API and the CSV import share them). @return array<string, mixed> */
    public static function rules(bool $creating): array
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

    /** A barcode as digits only ("0602 537-531159" becomes "0602537531159"); null when there are none. */
    public static function normalizeBarcode(?string $barcode): ?string
    {
        return preg_replace('/\D/', '', (string) $barcode) ?: null;
    }

    /**
     * Every way the same product code is written: UPC-A (12 digits), EAN-13 and GTIN-14 only differ by leading zeros.
     *
     * @return list<string>
     */
    public static function barcodeVariants(?string $barcode): array
    {
        $digits = self::normalizeBarcode($barcode);
        if ($digits === null || ltrim($digits, '0') === '') {
            return [];
        }

        $core = ltrim($digits, '0');

        return array_values(array_unique(array_filter(
            array_map(fn (int $length) => strlen($core) <= $length ? str_pad($core, $length, '0', STR_PAD_LEFT) : null, [strlen($core), 8, 12, 13, 14]),
        )));
    }

    /** @param  array<string, mixed>  $data  PhysicalItems::FIELDS */
    public static function add(array $data): PhysicalItem
    {
        $item = new PhysicalItem(array_intersect_key($data, array_flip(self::FIELDS)));
        $item->barcode = self::normalizeBarcode($item->barcode);
        $item->album_id = self::albumFor($item)?->id;
        $item->save();

        self::refresh($item);

        return $item;
    }

    /** @param  array<string, mixed>  $data */
    public static function update(PhysicalItem $item, array $data): PhysicalItem
    {
        $before = $item->album_id;
        $item->fill(array_intersect_key($data, array_flip(self::FIELDS)));
        $item->barcode = self::normalizeBarcode($item->barcode);
        if ($item->isDirty(['artist', 'title'])) {
            $item->album_id = self::albumFor($item)?->id;
        }
        $item->save();

        self::refresh($item, $before);

        return $item;
    }

    public static function remove(PhysicalItem $item): void
    {
        $album = $item->album;
        $groupId = $item->release_group_mbid;
        $item->delete();

        // The wish this item closed opens again when it was the last owned copy.
        CatalogueLinker::refreshPhysical($groupId, $album);
    }

    /** The library album an item belongs to: same artist and title key. */
    public static function albumFor(PhysicalItem $item): ?Album
    {
        $item->artist_key ??= \App\Domain\Library\Normalizer::artist($item->artist);
        $item->title_key ??= \App\Domain\Library\Normalizer::album($item->title);

        return Album::query()->where('name_key', $item->title_key)
            ->whereHas('artist', fn ($q) => $q->where('name_key', $item->artist_key))->orderBy('id')->first();
    }

    /** Closes the wish on the item's release-group (and opens the one of the album it left). */
    private static function refresh(PhysicalItem $item, ?int $previousAlbum = null): void
    {
        CatalogueLinker::refreshPhysical($item->release_group_mbid, $item->album);
        if ($previousAlbum !== null && $previousAlbum !== $item->album_id && ($old = Album::find($previousAlbum))) {
            CatalogueLinker::refreshOwnership($old);
        }
    }

    /** @return array<string, mixed> */
    public static function item(PhysicalItem $item): array
    {
        return [
            'id' => $item->id,
            'artist' => $item->artist,
            'title' => $item->title,
            'year' => $item->year,
            'medium' => $item->medium,
            'barcode' => $item->barcode,
            'catalogue_number' => $item->catalogue_number,
            'condition' => $item->condition,
            'notes' => $item->notes,
            'location' => $item->location,
            'acquired_on' => $item->acquired_on?->toDateString(),
            'album_id' => $item->album_id,
            'release_group_mbid' => $item->release_group_mbid,
            'ripped' => $item->ripped_at !== null,
            'ripped_at' => $item->ripped_at?->toIso8601String(),
        ];
    }

    /** The catalogue rows an item makes owned: its release-group, or the row linked to its library album. @return list<CatalogueAlbum> */
    public static function catalogueRows(?string $releaseGroupMbid, ?Album $album): array
    {
        $rows = CatalogueAlbum::query()->where(function ($q) use ($releaseGroupMbid, $album) {
            if ($releaseGroupMbid) {
                $q->orWhere('release_group_mbid', $releaseGroupMbid);
            }
            if ($album) {
                $q->orWhere('album_id', $album->id);
            }
        })->get();

        return $rows->all();
    }
}
