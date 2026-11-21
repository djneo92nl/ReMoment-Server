<?php

namespace App\Domain\Sync;

use App\Domain\Export\ExportFormats;
use App\Domain\Export\ExportItem;
use App\Domain\Export\ExportLoudness;
use App\Domain\Export\ExportNames;
use App\Domain\Export\ExportPlanner;
use App\Domain\Storage\Proxies;
use App\Models\File;
use App\Models\Media\Track;
use App\Models\SyncArtifact;

/**
 * Turns a rule set into "what the target should hold" and diffs it against what the target says it holds
 * (docs/architecture/offline-sync.md sections 2 and 3). Pure with respect to the target: it is stateless, reads the
 * library and the artifact table, and never writes (preparing artifacts is `SyncService`'s job).
 *
 * Steps: selections to units of tracks (SyncSelection) -> a decision per file with `ExportPlanner::decide()`
 * (copy, or transcode/remux = an artifact) -> key `track:{id}`, fingerprint, size -> budget and fill (all, priority,
 * fill_rest; whole albums together; sticky: tracks already on the target stay while they fit) -> churn limit -> sticky
 * paths that are a function of the track alone -> `SyncDiff`.
 */
final class SyncPlanner
{
    /** Bump when the way files are made or named changes: every artifact fingerprint changes with it. */
    public const VERSION = 1;

    public function __construct(private readonly ExportPlanner $export = new ExportPlanner) {}

    /**
     * @param  array<string, mixed>  $rules  normalised (SyncRules)
     * @param  list<array<string, mixed>>  $have  entries `{key, fingerprint, path, size}` the target holds
     * @param  array{free_bytes?: ?int, fs?: ?string, manifest_rules_version?: ?int, rules_version?: ?int}  $context
     */
    public function plan(array $rules, array $have = [], array $context = []): SyncResult
    {
        $warnings = [];
        $haveByKey = [];
        foreach ($have as $h) {
            $haveByKey[$h['key']] = $h;
        }
        $owned = (int) array_sum(array_column($have, 'size'));
        $limit = $this->limit($rules, $owned, $context['free_bytes'] ?? null);

        $selections = $rules['selections'];
        if ($rules['fill'] === 'fill_rest' && $limit !== null) {
            // The implicit last selection: favourites, then most played, then newest, until the budget is used.
            array_push($selections, ['type' => 'favorites'], ['type' => 'most_played', 'limit' => 2000], ['type' => 'recently_added', 'limit' => 2000]);
        }

        // 1. Units of candidate tracks in priority then rank order; a track is taken by the first selection that has it.
        $units = [];
        $raw = [];
        $seen = [];
        $noFile = 0;
        foreach ($selections as $si => $selection) {
            foreach (SyncSelection::units($selection, $warnings) as $unit) {
                $tracks = [];
                foreach ($unit['tracks'] as $track) {
                    if (isset($seen[$track->id])) {
                        continue;
                    }
                    $seen[$track->id] = true;
                    $tracks[] = $track;
                }
                if ($tracks !== []) {
                    $units[] = ['sel' => $si, 'whole' => $unit['whole'], 'tracks' => $tracks];
                }
            }
        }
        foreach ($units as $ui => $unit) {
            foreach ($unit['tracks'] as $track) {
                $raw[$track->id] = $track;
            }
        }

        // 2. A decision, fingerprint and size per track.
        $built = $this->candidates($raw, $rules, $noFile);
        $fingerprints = array_values(array_filter(array_map(fn ($c) => $c['delivery'] === 'artifact' ? $c['fingerprint'] : null, $built)));
        $artifacts = [];
        foreach (array_chunk($fingerprints, 500) as $chunk) {
            foreach (SyncArtifact::whereIn('fingerprint', $chunk)->get() as $a) {
                $artifacts[$a->fingerprint] = $a;
            }
        }

        $fat = in_array(strtolower((string) ($context['fs'] ?? '')), ['vfat', 'msdos', 'fat', 'fat32'], true);
        $cacheOff = 0;
        $tooLarge = [];
        $rank = 0;
        $cands = [];
        foreach ($units as $ui => $unit) {
            foreach ($unit['tracks'] as $track) {
                $c = $built[$track->id] ?? null;
                if ($c === null) {
                    continue;
                }
                if ($c['delivery'] === 'artifact') {
                    if (!SyncArtifacts::enabled()) {
                        $cacheOff++;

                        continue;
                    }
                    if (($a = $artifacts[$c['fingerprint']] ?? null) && $a->state === SyncArtifact::READY) {
                        $c['size'] = $a->size;
                    }
                }
                if ($fat && $c['size'] > (int) config('sync.fat32_max_bytes')) {
                    $tooLarge[] = $track->name;

                    continue;
                }
                $c['unit'] = $ui;
                $c['sel'] = $unit['sel'];
                $c['rank'] = $rank++;
                $cands[$track->id] = $c;
            }
        }
        if ($noFile > 0) {
            $warnings[] = ['code' => 'no_own_file', 'count' => $noFile];
        }
        if ($cacheOff > 0) {
            $warnings[] = ['code' => 'artifact_cache_off', 'count' => $cacheOff];
        }
        if ($tooLarge !== []) {
            $warnings[] = ['code' => 'too_large_for_fat32', 'count' => count($tooLarge), 'examples' => array_slice($tooLarge, 0, 5)];
        }

        // 3. Budget and fill.
        $candidateBytes = (int) array_sum(array_column($cands, 'size'));
        $overBudget = false;
        $chosen = $cands;
        if ($limit !== null) {
            if ($rules['fill'] === 'all') {
                $overBudget = $candidateBytes > $limit;
            } else {
                $chosen = $this->fill($units, $cands, $haveByKey, $limit);
            }
        }

        // 4. Churn: when nothing but the library moved, a plan adds and drops only so much.
        $deferred = ['count' => 0, 'bytes' => 0];
        $churn = (int) $rules['max_churn_percent'];
        $manifestVersion = $context['manifest_rules_version'] ?? null;
        if ($have !== [] && $churn > 0 && $manifestVersion !== null && $manifestVersion === ($context['rules_version'] ?? null)) {
            [$chosen, $deferred] = $this->limitChurn($chosen, $cands, $haveByKey, (int) floor($owned * $churn / 100));
        }

        // 5. Paths, then the diff.
        $paths = $this->paths($chosen, $haveByKey, $rules['options']['layout']);
        $entries = [];
        $wanted = [];
        foreach ($chosen as $c) {
            $key = $c['key'];
            $entries[] = ['key' => $key, 'fingerprint' => $c['fingerprint'], 'path' => $paths[$key], 'size' => $c['size']];
            $wanted[$key] = $c + ['path' => $paths[$key]];
        }
        $diff = SyncDiff::diff($entries, array_values($haveByKey));

        $copied = count(array_filter($wanted, fn ($c) => $c['delivery'] === 'copy'));
        $missing = [];
        foreach ($wanted as $c) {
            if ($c['delivery'] === 'artifact' && $c['spec']['target'] !== null && ExportFormats::missing($c['spec']['target']['codec'])) {
                $missing[$c['spec']['target']['codec']] = true;
            }
        }

        $removals = count($diff->remove);
        $confirm = $have !== [] && (
            ($removals > 0 && ($removals > (int) config('sync.confirm_remove_files') || $removals / count($have) * 100 > (int) config('sync.confirm_remove_percent')))
            || $rules['selections'] === []
        );
        $free = $context['free_bytes'] ?? null;

        return new SyncResult(
            $diff, $wanted, $warnings, $overBudget, $limit, count($cands), $candidateBytes,
            (int) array_sum(array_column($entries, 'size')), $copied, count($wanted) - $copied, $deferred, array_keys($missing), $confirm,
            $free !== null && $diff->bytesToAdd() > $free && $diff->bytesFreed() > 0,
        );
    }

    /**
     * The entry one track would have under these rules (key, fingerprint, size, delivery, spec), or null without an own
     * file. Used to check a download request against the current rules.
     *
     * @param  array<string, mixed>  $rules  normalised
     * @return array<string, mixed>|null
     */
    public function entryFor(array $rules, Track $track): ?array
    {
        $noFile = 0;

        return $this->candidates([$track->id => $track], $rules, $noFile)[$track->id] ?? null;
    }

    /**
     * @param  array<int, Track>  $tracks  id => track
     * @return array<int, array<string, mixed>> id => candidate (tracks without an own file are left out and counted)
     */
    private function candidates(array $tracks, array $rules, int &$noFile): array
    {
        $profile = $rules['profile'];
        $p = config("export.profiles.{$profile}");
        $loudness = $rules['options']['loudness'];

        $items = [];
        foreach ($tracks as $id => $track) {
            $file = Proxies::original($track);
            if (!$file) {
                $noFile++;

                continue;
            }
            $items[$id] = ['track' => $track, 'file' => $file, 'item' => $this->export->decide($file, $profile, $id)];
        }

        $remux = fn (ExportItem $i) => $i->action === ExportPlanner::COPY && !empty($p['id3v23']) && $i->sourceCodec === 'mp3';

        // Album gain from every transcoded track of the album, not only the selected ones, so a selection change never moves a level.
        $gains = $loudness === 'off' ? [] : $this->gains($items, $rules);

        $out = [];
        foreach ($items as $id => $c) {
            /** @var ExportItem $item */
            $item = $c['item'];
            $file = $c['file'];
            $gain = $item->action === ExportPlanner::TRANSCODE ? ($gains[$id] ?? null) : null;
            if ($gain !== null) {
                $item = $item->withGain($gain);
            }
            $artifact = $item->action === ExportPlanner::TRANSCODE || $remux($item);
            $target = $item->action === ExportPlanner::TRANSCODE ? $item->target : null;
            $extension = $item->action === ExportPlanner::TRANSCODE
                ? ExportFormats::extension($target['codec'])
                : (strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) ($file->extension ?: pathinfo($file->path, PATHINFO_EXTENSION))) ?: 'mp3'));
            $source = $file->hash ?: sha1("{$file->path}|{$file->size}|{$file->mtime}");

            $out[$id] = [
                'track' => $c['track'], 'file' => $c['file'], 'key' => "track:{$id}",
                'delivery' => $artifact ? 'artifact' : 'copy',
                'extension' => $extension,
                'fingerprint' => $artifact
                    ? $this->artifactFingerprint($source, $target, $remux($item))
                    : $source,
                'size' => $artifact && $remux($item) ? (int) $file->size : $item->estimatedBytes,
                'sha256' => !$artifact && strlen((string) $file->hash) === 64 ? $file->hash : null,
                'spec' => ['track_id' => $id, 'file_id' => $file->id, 'extension' => $extension, 'target' => $target, 'remux' => $remux($item), 'estimate' => $item->estimatedBytes],
            ];
        }

        return $out;
    }

    /** sha1 of what makes the bytes: source content, the effective encode (codec, bitrate, rate, channels, gain), the cover limit, this planner's version. */
    private function artifactFingerprint(string $source, ?array $target, bool $remux): string
    {
        $encode = $remux ? 'remux-id3v23' : implode(',', [
            $target['codec'], (int) $target['bitrate'], (int) ($target['sample_rate'] ?? 0), (int) $target['stereo'],
            $target['gain_db'] === null ? 'nogain' : number_format((float) $target['gain_db'], 1, '.', ''),
        ]);

        return sha1(implode('|', ['v'.self::VERSION, $source, $encode, (int) config('export.cover_max_px')]));
    }

    /**
     * Loudness gains of the transcoded tracks, the album mean taken over all the album's transcoded tracks in the library.
     *
     * @param  array<int, array{track: Track, file: File, item: ExportItem}>  $items
     * @return array<int, ?float>
     */
    private function gains(array $items, array $rules): array
    {
        $entries = [];
        foreach ($items as $id => $c) {
            if ($c['item']->action === ExportPlanner::TRANSCODE) {
                $entries[$id] = ['track' => $c['track'], 'file' => $c['file']];
            }
        }
        if ($entries === []) {
            return [];
        }

        if ($rules['options']['loudness'] === 'album') {
            $albumIds = array_values(array_unique(array_filter(array_map(fn ($e) => $e['track']->album_id, $entries))));
            foreach (Track::whereIn('album_id', $albumIds)->get() as $other) {
                if (isset($entries[$other->id]) || !($file = Proxies::original($other))) {
                    continue;
                }
                if ($this->export->decide($file, $rules['profile'], $other->id)->action === ExportPlanner::TRANSCODE) {
                    $entries[$other->id] = ['track' => $other, 'file' => $file];
                }
            }
        }

        return (new ExportLoudness)->gains($entries, $rules['options']['loudness']);
    }

    /** The usable bytes: the budget, held to what fits on the target (own bytes plus free space), less the reserve. */
    private function limit(array $rules, int $owned, ?int $free): ?int
    {
        $cap = $rules['budget']['bytes'];
        if ($free !== null) {
            $fit = $owned + max(0, $free);
            $cap = $cap === null ? $fit : min($cap, $fit);
        }

        return $cap === null ? null : (int) floor($cap * (1 - $rules['budget']['reserve_percent'] / 100));
    }

    /**
     * priority / fill_rest: the tracks already on the target stay while they still fit (lowest priority and rank go out
     * first when the budget forces it), then the free space goes to the best ranked new ones. A whole album (album, artist,
     * favorites) is kept or dropped together; the first unit of a selection that does not fit stops that selection.
     *
     * @param  list<array{sel: int, whole: bool, tracks: list<Track>}>  $units
     * @param  array<int, array<string, mixed>>  $cands
     * @param  array<string, array<string, mixed>>  $haveByKey
     * @return array<int, array<string, mixed>>
     */
    private function fill(array $units, array $cands, array $haveByKey, int $limit): array
    {
        $chosen = [];
        $used = 0;
        $groups = [];
        foreach ($units as $unit) {
            $ids = array_values(array_filter(array_map(fn (Track $t) => isset($cands[$t->id]) ? $t->id : null, $unit['tracks'])));
            if ($ids !== []) {
                $groups[] = ['sel' => $unit['sel'], 'whole' => $unit['whole'], 'ids' => $ids];
            }
        }
        $onTarget = fn (int $id) => isset($haveByKey[$cands[$id]['key']]);
        $take = function (array $ids) use (&$chosen, &$used, $cands, $limit): bool {
            $fresh = array_values(array_filter($ids, fn ($id) => !isset($chosen[$id])));
            $bytes = (int) array_sum(array_map(fn ($id) => $cands[$id]['size'], $fresh));
            if ($used + $bytes > $limit) {
                return false;
            }
            foreach ($fresh as $id) {
                $chosen[$id] = $cands[$id];
            }
            $used += $bytes;

            return true;
        };

        foreach ($groups as $g) {
            if ($g['whole']) {
                if (array_filter($g['ids'], $onTarget) !== []) {
                    $take($g['ids']);
                }
            } else {
                foreach ($g['ids'] as $id) {
                    $onTarget($id) && $take([$id]);
                }
            }
        }

        $stopped = [];
        foreach ($groups as $g) {
            if (isset($stopped[$g['sel']])) {
                continue;
            }
            foreach ($g['whole'] ? [$g['ids']] : array_map(fn ($id) => [$id], $g['ids']) as $ids) {
                if (isset($stopped[$g['sel']])) {
                    break;
                }
                if (!$take($ids)) {
                    $stopped[$g['sel']] = true;
                }
            }
        }

        uasort($chosen, fn ($a, $b) => $a['rank'] <=> $b['rank']);

        return $chosen;
    }

    /**
     * Holds back new units beyond `$allowance` bytes (what the budget forced out of the target counts against it), but
     * always lets the first new unit through so a unit bigger than the allowance can never starve.
     *
     * @param  array<int, array<string, mixed>>  $chosen
     * @param  array<int, array<string, mixed>>  $cands
     * @param  array<string, array<string, mixed>>  $haveByKey
     * @return array{0: array<int, array<string, mixed>>, 1: array{count: int, bytes: int}}
     */
    private function limitChurn(array $chosen, array $cands, array $haveByKey, int $allowance): array
    {
        $forced = 0;
        foreach ($cands as $id => $c) {
            if (isset($haveByKey[$c['key']]) && !isset($chosen[$id])) {
                $forced += (int) $haveByKey[$c['key']]['size'];
            }
        }
        $left = max(0, $allowance - $forced);

        $byUnit = [];
        foreach ($chosen as $id => $c) {
            if (!isset($haveByKey[$c['key']])) {
                $byUnit[$c['unit']][$id] = $c;
            }
        }
        ksort($byUnit);

        $added = 0;
        $deferred = ['count' => 0, 'bytes' => 0];
        foreach ($byUnit as $new) {
            $bytes = (int) array_sum(array_column($new, 'size'));
            if ($added === 0 || $added + $bytes <= $left) {
                $added += $bytes;

                continue;
            }
            foreach (array_keys($new) as $id) {
                unset($chosen[$id]);
            }
            $deferred['count'] += count($new);
            $deferred['bytes'] += $bytes;
        }

        return [$chosen, $deferred];
    }

    /**
     * One path per wanted track, a function of the track alone: `Artist/Album/01 - Title.ext` (flat: `Artist - Title.ext`).
     * An entry the target already holds keeps its path unless its own name changed (a "(2)" collision suffix counts as
     * the same name); a new one resolves collisions against every path in use, so existing files are never renumbered.
     *
     * @param  array<int, array<string, mixed>>  $chosen
     * @param  array<string, array<string, mixed>>  $haveByKey
     * @return array<string, string> key => path
     */
    private function paths(array $chosen, array $haveByKey, string $layout): array
    {
        $used = [];
        foreach ($haveByKey as $h) {
            $used[mb_strtolower($h['path'])] = $h['key'];
        }

        $canonical = [];
        foreach ($chosen as $c) {
            /** @var Track $t */
            $t = $c['track'];
            if ($layout === 'flat') {
                $dir = '';
                $base = ExportNames::limit(ExportNames::clean($t->artist?->name, 'Unknown Artist', 40).' - '.ExportNames::clean($t->name, 'Untitled'));
            } else {
                [$dir, $base] = ExportNames::treeParts($t->artist?->name, $t->album?->name, $t->name, (int) $t->metaValue('track_number') ?: null, (int) $t->metaValue('disc_number') ?: null);
                $base = ExportNames::limit($base);
            }
            $canonical[$c['key']] = [$dir, $base, $c['extension']];
        }

        $paths = [];
        foreach ($canonical as $key => [$dir, $base, $ext]) {
            $have = $haveByKey[$key]['path'] ?? null;
            if ($have !== null && preg_match('/^'.preg_quote($dir.$base, '/').'( \(\d+\))?\.'.preg_quote($ext, '/').'$/iu', $have)) {
                $paths[$key] = $have;
            }
        }
        foreach ($canonical as $key => [$dir, $base, $ext]) {
            if (isset($paths[$key])) {
                continue;
            }
            $path = "{$dir}{$base}.{$ext}";
            for ($n = 2; isset($used[mb_strtolower($path)]) && $used[mb_strtolower($path)] !== $key; $n++) {
                $path = "{$dir}{$base} ({$n}).{$ext}";
            }
            $used[mb_strtolower($path)] = $key;
            $paths[$key] = $path;
        }

        return $paths;
    }
}
