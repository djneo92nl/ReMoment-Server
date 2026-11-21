<?php

namespace App\Domain\Catalogue;

use App\Domain\Library\Normalizer;
use App\Jobs\LookupPhysicalBarcode;
use App\Models\Media\PhysicalItem;
use App\Services\MusicBrainz\MusicBrainzClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * CSV import of a physical collection (workstream 6, docs/api/physical.md): a preview that writes nothing and tells
 * what each row would become, then a confirm step that adds the rows. Barcodes are looked up on MusicBrainz by queued,
 * rate-limited jobs after the confirm, never inline, so a few hundred rows cost no request time.
 *
 * Formats: the Discogs collection export (recognised by its headers) and any CSV whose headers say what a column is
 * (`artist`, `title`, `medium`/`format`, `year`, `barcode`, `location`, ...; `mapping` names a column explicitly).
 */
final class PhysicalImport
{
    public const MAX_ROWS = 5000;

    public const MAX_BYTES = 2_000_000;

    /** Seconds a preview can be confirmed. */
    public const TTL = 3600;

    /** Seconds between two queued barcode lookups (MusicBrainz allows about one request a second; the `musicbrainz` limiter spaces them too). */
    public const LOOKUP_SPACING = 2;

    public const STATUSES = ['new', 'lookup', 'duplicate', 'invalid'];

    /** The fields of an item a column can be mapped to. */
    public const FIELDS = ['artist', 'title', 'medium', 'year', 'barcode', 'location', 'catalogue_number', 'condition', 'notes', 'acquired_on'];

    /** Normalised header (lower case, one space) => field. `sleeve` and `folder` are merged into notes and location (Discogs). */
    private const ALIASES = [
        'artist' => 'artist', 'artist name' => 'artist', 'album artist' => 'artist', 'band' => 'artist', 'performer' => 'artist',
        'title' => 'title', 'album' => 'title', 'album title' => 'title', 'release title' => 'title', 'release' => 'title', 'name' => 'title',
        'medium' => 'medium', 'format' => 'medium', 'media' => 'medium', 'media type' => 'medium', 'type' => 'medium',
        'year' => 'year', 'released' => 'year', 'release year' => 'year', 'release date' => 'year',
        'barcode' => 'barcode', 'upc' => 'barcode', 'ean' => 'barcode', 'upc/ean' => 'barcode', 'gtin' => 'barcode',
        'location' => 'location', 'shelf' => 'location', 'place' => 'location', 'box' => 'location', 'storage' => 'location',
        'catalogue number' => 'catalogue_number', 'catalog number' => 'catalogue_number', 'catalog#' => 'catalogue_number', 'catalogue#' => 'catalogue_number',
        'cat#' => 'catalogue_number', 'cat no' => 'catalogue_number', 'catno' => 'catalogue_number', 'catalogue no' => 'catalogue_number',
        'condition' => 'condition', 'media condition' => 'condition', 'collection media condition' => 'condition',
        'notes' => 'notes', 'note' => 'notes', 'comments' => 'notes', 'comment' => 'notes', 'collection notes' => 'notes',
        'acquired on' => 'acquired_on', 'acquired' => 'acquired_on', 'date added' => 'acquired_on', 'purchase date' => 'acquired_on', 'bought on' => 'acquired_on',
        'collection sleeve condition' => 'sleeve', 'sleeve condition' => 'sleeve',
        'collectionfolder' => 'folder', 'collection folder' => 'folder',
    ];

    /**
     * Reads the text, classifies every row against the collection and keeps the result for the confirm step.
     *
     * @param  array<string, string>  $mapping  field => the header of the column that holds it (overrides what the headers say)
     * @param  int|null  $limit  how many rows `data` lists (all by default; `summary` always counts every row)
     * @return array{token: string, expires_in: int, format: string, mapping: array<string, string>, unmapped: list<string>, summary: array<string, int>, truncated: bool, data: list<array<string, mixed>>}
     *
     * @throws \InvalidArgumentException when the text is no usable CSV
     */
    public static function preview(string $text, ?string $defaultMedium = null, array $mapping = [], ?int $limit = null): array
    {
        $parsed = self::parse($text, $defaultMedium, $mapping);
        $rows = self::classify($parsed['rows']);

        $token = Str::random(40);
        Cache::put(self::cacheKey($token), ['rows' => $parsed['rows']], self::TTL);

        return [
            'token' => $token, 'expires_in' => self::TTL, 'format' => $parsed['format'], 'mapping' => $parsed['mapping'], 'unmapped' => $parsed['unmapped'],
            'summary' => self::summary($rows), 'truncated' => $limit !== null && count($rows) > $limit,
            'data' => array_map([self::class, 'present'], $limit === null ? $rows : array_slice($rows, 0, $limit)),
        ];
    }

    /** The rows of a preview that can still be confirmed, classified again (the collection may have changed since); null when it expired. @return list<array<string, mixed>>|null */
    public static function rows(string $token): ?array
    {
        $kept = Cache::get(self::cacheKey($token));

        return $kept === null ? null : self::classify($kept['rows']);
    }

    /**
     * Adds the rows of a preview: `new` rows at once, `lookup` rows too when they have a name, the rest after their
     * barcode was looked up on MusicBrainz. The preview is used up.
     *
     * @return array{added: int, queued_lookups: int, skipped_duplicates: int, skipped_invalid: int}|null null when the preview expired
     */
    public static function confirm(string $token): ?array
    {
        $rows = self::rows($token);
        if ($rows === null) {
            return null;
        }
        Cache::forget(self::cacheKey($token));

        $added = $queued = 0;
        foreach ($rows as $row) {
            if (!in_array($row['status'], ['new', 'lookup'], true)) {
                continue;
            }

            $fields = self::fields($row);
            $named = $row['artist'] !== null && $row['title'] !== null;
            $item = $named ? PhysicalItems::add($fields) : null;
            $added += $item ? 1 : 0;

            if ($row['status'] === 'lookup') {
                LookupPhysicalBarcode::dispatch($item, $fields)->delay(now()->addSeconds($queued * self::LOOKUP_SPACING));
                $queued++;
            }
        }

        $summary = self::summary($rows);

        return ['added' => $added, 'queued_lookups' => $queued, 'skipped_duplicates' => $summary['duplicate'], 'skipped_invalid' => $summary['invalid']];
    }

    /**
     * The queued step of a barcode row: asks MusicBrainz for the release and ties the item to it (release ids close a wish
     * on the release-group). A row without a name has no item yet: it is added from the release, or, when MusicBrainz knows
     * nothing, under a placeholder name so the row is not lost. A rate limit or outage throws, so the job is retried.
     *
     * @param  array<string, mixed>  $fields  the row as item fields (PhysicalItems::FIELDS)
     */
    public static function resolve(?PhysicalItem $item, array $fields, ?MusicBrainzClient $mb = null): ?PhysicalItem
    {
        $barcode = PhysicalItems::normalizeBarcode($fields['barcode'] ?? null);
        if ($barcode === null || $item?->release_mbid !== null) {
            return $item; // nothing to look up, or already tied to a release
        }
        if ($item === null && self::existingByBarcode([$barcode]) !== []) {
            return null; // a retry of a job that already added it, or the user added it meanwhile
        }

        $candidates = self::bestFirst(PhysicalItems::lookupBarcode($barcode, $mb), $fields['medium'] ?? 'cd');

        if ($item !== null) {
            $match = self::matching($candidates, $item);
            if ($match === null) {
                return $item;
            }

            return PhysicalItems::update($item, array_filter([
                'release_group_mbid' => $match['release_group_mbid'], 'release_mbid' => $match['release_mbid'],
                'catalogue_number' => $item->catalogue_number === null ? $match['catalogue_number'] : null,
                'year' => $item->year === null ? $match['year'] : null,
            ], fn ($v) => $v !== null));
        }

        $match = $candidates[0] ?? null;
        if ($match === null) {
            return self::addPlaceholder($fields, 'MusicBrainz knows no release with this barcode.');
        }

        // What the row says (medium, location, condition ...) wins over the release; the release names it.
        return PhysicalItems::add(array_merge(array_filter([
            'artist' => $match['artist'], 'title' => $match['title'], 'year' => $match['year'], 'catalogue_number' => $match['catalogue_number'],
            'release_group_mbid' => $match['release_group_mbid'], 'release_mbid' => $match['release_mbid'],
        ], fn ($v) => $v !== null && $v !== ''), $fields));
    }

    /** A row whose barcode could not be resolved is kept under a name that says so, searchable by its barcode. @param  array<string, mixed>  $fields */
    public static function addPlaceholder(array $fields, string $why): ?PhysicalItem
    {
        $barcode = PhysicalItems::normalizeBarcode($fields['barcode'] ?? null);
        if ($barcode !== null && self::existingByBarcode([$barcode]) !== []) {
            return null;
        }

        return PhysicalItems::add([
            'artist' => 'Unknown artist', 'title' => 'Barcode '.($barcode ?? '?'),
            'notes' => trim(($fields['notes'] ?? '').' '.$why.' Imported from CSV; fix the name by hand.'),
        ] + $fields);
    }

    /** @param  list<array<string, mixed>>  $rows @return array<string, int> */
    public static function summary(array $rows): array
    {
        $summary = ['rows' => count($rows)] + array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $row) {
            $summary[$row['status']]++;
        }

        return $summary;
    }

    /** @param  array<string, mixed>  $row @return array<string, mixed> */
    public static function present(array $row): array
    {
        return array_intersect_key($row, array_flip(['row', 'status', 'reason', 'duplicate_of', ...self::FIELDS]));
    }

    // ------------------------------------------------------------------ parsing

    /**
     * @param  array<string, string>  $mapping
     * @return array{format: string, mapping: array<string, string>, unmapped: list<string>, rows: list<array<string, mixed>>}
     */
    public static function parse(string $text, ?string $defaultMedium = null, array $mapping = []): array
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The file is larger than '.(self::MAX_BYTES / 1_000_000).' MB.');
        }
        if ($defaultMedium !== null && $defaultMedium !== '' && !in_array($defaultMedium, PhysicalItem::MEDIA, true)) {
            throw new \InvalidArgumentException("Unknown default medium '{$defaultMedium}'.");
        }

        $records = self::records(self::utf8($text));
        $header = array_shift($records);
        if ($header === null || count(array_filter($header, fn ($h) => trim((string) $h) !== '')) === 0) {
            throw new \InvalidArgumentException('The file is empty: the first line must be the column names.');
        }

        $normalised = array_map(fn ($h) => self::headerKey((string) $h), $header);
        $format = in_array('release_id', $normalised, true) && (in_array('collectionfolder', $normalised, true) || in_array('catalog#', $normalised, true)) ? 'discogs' : 'generic';

        $columns = []; // field => column index
        foreach ($mapping as $field => $name) {
            if (!in_array($field, self::FIELDS, true)) {
                throw new \InvalidArgumentException("Cannot map '{$field}': the fields are ".implode(', ', self::FIELDS).'.');
            }
            $index = array_search(self::headerKey((string) $name), $normalised, true);
            if ($index === false) {
                throw new \InvalidArgumentException("The file has no column '{$name}'.");
            }
            $columns[$field] = $index;
        }
        foreach ($normalised as $index => $key) {
            $field = self::ALIASES[$key] ?? null;
            if ($field !== null && !isset($columns[$field]) && !in_array($index, $columns, true)) {
                $columns[$field] = $index;
            }
        }

        $hasName = isset($columns['artist'], $columns['title']);
        if (!$hasName && !isset($columns['barcode'])) {
            throw new \InvalidArgumentException('No artist and title columns, and no barcode column, found in: '.implode(', ', array_filter(array_map('trim', $header))).'. Map the columns with `mapping`.');
        }

        $rows = [];
        foreach ($records as $i => $record) {
            if (count(array_filter($record, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new \InvalidArgumentException('More than '.self::MAX_ROWS.' rows: split the file.');
            }
            $get = fn (string $field) => isset($columns[$field]) ? (trim((string) ($record[$columns[$field]] ?? '')) ?: null) : null;
            $rows[] = self::row($i + 2, $get, $format, $defaultMedium ?: null);
        }

        $mappedHeaders = array_map(fn ($index) => trim((string) $header[$index]), $columns);

        return [
            'format' => $format,
            'mapping' => $mappedHeaders,
            'unmapped' => array_values(array_filter(array_map('trim', array_diff_key($header, array_flip($columns))), fn ($h) => $h !== '')),
            'rows' => $rows,
        ];
    }

    /** @param  callable(string): ?string  $get @return array<string, mixed> */
    private static function row(int $number, callable $get, string $format, ?string $defaultMedium): array
    {
        $artist = $get('artist');
        if ($artist !== null && $format === 'discogs') {
            $artist = preg_replace('/\s*\(\d+\)$/', '', $artist) ?: $artist; // "Nirvana (2)": Discogs' way to tell artists with the same name apart
        }

        $notes = collect([$get('notes'), ($sleeve = $get('sleeve')) ? "Sleeve: {$sleeve}" : null])->filter()->implode("\n") ?: null;
        $folder = $get('folder');
        $barcodeRaw = $get('barcode');

        $row = [
            'row' => $number, 'status' => 'new', 'reason' => null, 'error' => null, 'duplicate_of' => null,
            'artist' => $artist, 'title' => $get('title'), 'medium' => self::medium($get('medium')) ?? $defaultMedium,
            'year' => self::year($get('year')), 'barcode' => PhysicalItems::normalizeBarcode($barcodeRaw),
            'location' => $get('location') ?? ($folder !== null && !in_array(strtolower($folder), ['uncategorized', 'all'], true) ? $folder : null),
            'catalogue_number' => $get('catalogue_number'), 'condition' => $get('condition'), 'notes' => $notes,
            'acquired_on' => self::date($get('acquired_on')),
        ];

        $row['error'] = self::problem($row, $barcodeRaw);

        return $row;
    }

    /** What is wrong with a row that cannot be imported, or null. @param  array<string, mixed>  $row */
    private static function problem(array $row, ?string $barcodeRaw): ?string
    {
        if ($barcodeRaw !== null && preg_match('/^\d+(\.\d+)?e\+?\d+$/i', $barcodeRaw)) {
            return "Barcode '{$barcodeRaw}' was damaged by a spreadsheet (scientific notation): format the column as text and export again.";
        }
        if ($row['barcode'] !== null && (strlen($row['barcode']) < 6 || strlen($row['barcode']) > 20)) {
            return 'A barcode is 6 to 20 digits.';
        }
        if ($row['medium'] === null) {
            return 'No medium: add a medium or format column, or choose a default medium.';
        }
        if (($row['artist'] === null) !== ($row['title'] === null)) {
            return $row['artist'] === null ? 'Artist is missing.' : 'Title is missing.';
        }
        if ($row['artist'] === null && $row['barcode'] === null) {
            return 'Artist and title are missing, and there is no barcode to look them up by.';
        }

        $validator = Validator::make(array_filter($row, fn ($v) => $v !== null), array_diff_key(PhysicalItems::rules(false), ['release_group_mbid' => 1, 'release_mbid' => 1]));

        return $validator->fails() ? $validator->errors()->first() : null;
    }

    /** A medium from free text ("Vinyl, LP, Album", "2xCD", "Cassette", "MD"); null when the text is empty, `other` when it is none of ours. */
    public static function medium(?string $text): ?string
    {
        $text = strtolower(trim((string) $text));
        if ($text === '') {
            return null;
        }
        $text = preg_replace('/\b\d+\s*x\s*/', '', $text) ?? $text; // "2xCD": a double album is still a CD

        return match (true) {
            (bool) preg_match('/mini\s*-?\s*disc|\bmd\b/', $text) => 'minidisc',
            (bool) preg_match('/cassette|kassette|\btape\b|\bmc\b|\bcass\b/', $text) => 'tape',
            (bool) preg_match('/vinyl|\blps?\b|record|schallplatte|\b(7|10|12)\s*(["\x{2033}]|in\b|inch)/u', $text) => 'vinyl',
            (bool) preg_match('/(?<![a-z])(cds?|cd-?r|sacd|hdcd)(?![a-z])|compact disc/', $text) => 'cd',
            default => 'other',
        };
    }

    private static function year(?string $text): ?int
    {
        if ($text !== null && preg_match('/(?<!\d)(\d{4})(?!\d)/', $text, $m) && $m[1] >= 1000 && $m[1] <= 2200) {
            return (int) $m[1];
        }

        return null;
    }

    private static function date(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        try {
            return Carbon::parse($text)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function headerKey(string $header): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($header, " \t\n\r\0\x0B\xEF\xBB\xBF"))) ?? '';
    }

    /** UTF-8 text without a byte order mark; a file saved by Excel in Windows-1252 is converted. */
    private static function utf8(string $text): string
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }

    /** The records of the text with the delimiter it uses (comma, semicolon or tab); quoted fields may hold line breaks. @return list<list<?string>> */
    private static function records(string $text): array
    {
        $firstLine = strtok($text, "\r\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $delimiter = array_key_first($counts);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        $records = [];
        while (($record = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $records[] = $record;
        }
        fclose($stream);

        return $records;
    }

    // ------------------------------------------------------------------ duplicates

    /**
     * Sets status, `reason` and `duplicate_of` of the parsed rows (those with an `error` are `invalid`): a row is a duplicate when the collection already has its
     * barcode, or its artist, title and medium; and when an earlier row of the file has the same (the first one wins).
     * A valid row with a barcode will be looked up on MusicBrainz (`lookup`), the others are `new`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function classify(array $rows): array
    {
        $barcodes = [];
        $names = [];
        foreach ($rows as $row) {
            if ($row['error'] !== null) {
                continue;
            }
            foreach (PhysicalItems::barcodeVariants($row['barcode']) as $variant) {
                $barcodes[] = $variant;
            }
            if ($row['artist'] !== null) {
                $names[] = Normalizer::artist($row['artist']);
            }
        }

        $byBarcode = self::existingByBarcode($barcodes);
        $byName = [];
        foreach (array_chunk(array_unique($names), 500) as $chunk) {
            foreach (PhysicalItem::whereIn('artist_key', $chunk)->get(['id', 'artist_key', 'title_key', 'medium']) as $item) {
                $byName[self::nameKey($item->artist_key, $item->title_key, $item->medium)] ??= $item->id;
            }
        }

        $seenBarcode = $seenName = [];
        foreach ($rows as &$row) {
            $row['reason'] = $row['error'];
            $row['duplicate_of'] = null;
            if ($row['error'] !== null) {
                $row['status'] = 'invalid';

                continue;
            }

            $variants = PhysicalItems::barcodeVariants($row['barcode']);
            $nameKey = $row['artist'] !== null ? self::nameKey(Normalizer::artist($row['artist']), Normalizer::album($row['title']), $row['medium']) : null;

            $existing = null;
            foreach ($variants as $variant) {
                $existing ??= $byBarcode[$variant] ?? null;
            }
            $byTitle = $nameKey !== null ? ($byName[$nameKey] ?? null) : null;

            if ($existing !== null || $byTitle !== null) {
                $row['status'] = 'duplicate';
                $row['duplicate_of'] = $existing ?? $byTitle;
                $row['reason'] = $existing !== null ? 'Already in the collection with this barcode.' : 'Already in the collection with this artist, title and medium.';
            } elseif (array_intersect($variants, array_keys($seenBarcode)) !== [] || ($nameKey !== null && isset($seenName[$nameKey]))) {
                $row['status'] = 'duplicate';
                $row['reason'] = 'Same barcode or artist, title and medium as row '.($seenName[$nameKey] ?? $seenBarcode[array_values(array_intersect($variants, array_keys($seenBarcode)))[0]]).' of the file.';
            } else {
                $row['status'] = $row['barcode'] !== null ? 'lookup' : 'new';
                foreach ($variants as $variant) {
                    $seenBarcode[$variant] = $row['row'];
                }
                if ($nameKey !== null) {
                    $seenName[$nameKey] = $row['row'];
                }
            }
        }
        unset($row);

        return $rows;
    }

    /** @param  list<string>  $variants @return array<string, int> barcode variant => item id */
    private static function existingByBarcode(array $variants): array
    {
        $found = [];
        foreach (array_chunk(array_unique($variants), 500) as $chunk) {
            foreach (PhysicalItem::whereIn('barcode', $chunk)->get(['id', 'barcode']) as $item) {
                $found[$item->barcode] ??= $item->id;
            }
        }

        return $found;
    }

    private static function nameKey(string $artistKey, string $titleKey, string $medium): string
    {
        return $artistKey.'|'.$titleKey.'|'.$medium;
    }

    // ------------------------------------------------------------------ lookup helpers

    /** Releases of the wanted medium first, MusicBrainz's order otherwise. @param  list<array<string, mixed>>  $candidates @return list<array<string, mixed>> */
    private static function bestFirst(array $candidates, string $medium): array
    {
        usort($candidates, fn ($a, $b) => ($b['medium'] === $medium) <=> ($a['medium'] === $medium));

        return $candidates;
    }

    /** The release of a named row: same artist (a title may differ by edition), the same title first. @param  list<array<string, mixed>>  $candidates @return array<string, mixed>|null */
    private static function matching(array $candidates, PhysicalItem $item): ?array
    {
        $sameArtist = array_values(array_filter($candidates, fn ($c) => Normalizer::artist($c['artist']) === $item->artist_key));
        foreach ($sameArtist as $candidate) {
            if (Normalizer::album($candidate['title']) === $item->title_key) {
                return $candidate;
            }
        }

        return $sameArtist[0] ?? null;
    }

    /** @param  array<string, mixed>  $row @return array<string, mixed> */
    private static function fields(array $row): array
    {
        return array_filter(array_intersect_key($row, array_flip(self::FIELDS)), fn ($v) => $v !== null);
    }

    private static function cacheKey(string $token): string
    {
        return 'physical-import:'.$token;
    }
}
