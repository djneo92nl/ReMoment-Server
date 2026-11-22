<?php

namespace App\Domain\Library;

use App\Models\Media\Metadata;
use App\Models\Media\Track;

/**
 * Related works of a track from MusicBrainz relationships (roadmap 12.7): this is a cover of, a remix of, samples, is
 * sampled by, an arrangement, other versions. No extra request: the recording lookup of EnrichTrackMusicBrainz asks for
 * `recording-rels`, `work-rels` and `work-level-rels`, and `parse()` keeps what it gets as the track's `related_works`
 * (json) and `work_mbid` (the MusicBrainz work the recording performs).
 *
 * A related item that is also in the library links to the track: a recording by its MusicBrainz id, a work by the
 * `work_mbid` of the tracks that perform it. `versions()` lists the other tracks performing the same work, which is
 * the "other versions in your library" list without any lookup (a cover and its original share a work).
 *
 * Direction (which side of a relation the lookup recording is) differs per type; LABELS holds it. The work types
 * are read from a real response (docs/api/related-works.md), the recording types (remix, samples, ...) are *unverified*.
 */
final class RelatedWorks
{
    public const SOURCE = Enrichment::MUSICBRAINZ;

    /** Most items kept per track. */
    public const MAX_ITEMS = 30;

    /** MusicBrainz relationship type => label per direction (`forward`: the lookup entity is entity0). */
    private const LABELS = [
        'remix' => ['forward' => 'Remix of', 'backward' => 'Remixed as'],
        'samples material' => ['forward' => 'Samples', 'backward' => 'Is sampled by'],
        'medley' => ['forward' => 'Medley of', 'backward' => 'Part of the medley'],
        'edit' => ['forward' => 'Edit of', 'backward' => 'Edited as'],
        'remaster' => ['forward' => 'Remaster of', 'backward' => 'Remastered as'],
    ];

    /** The same for work to work relations (the song, not one recording of it). */
    private const WORK_LABELS = [
        'arrangement' => ['forward' => 'Has an arrangement', 'backward' => 'Arrangement of'],
        'other version' => ['forward' => 'Other version', 'backward' => 'Other version'],
        'musical quotation' => ['forward' => 'Quotes', 'backward' => 'Is quoted in'],
        'based on' => ['forward' => 'Based on', 'backward' => 'Is the basis of'],
        'revision of' => ['forward' => 'Revision of', 'backward' => 'Revised as'],
    ];

    private const WRITER_ROLES = ['composer', 'writer', 'lyricist'];

    /**
     * What a MusicBrainz recording lookup says about related works.
     *
     * @return array{work_mbid: ?string, items: list<array{relation: string, label: string, target: string, mbid: string, title: string, artist: ?string, detail: ?string}>}
     */
    public static function parse(array $recording): array
    {
        $items = [];
        $workMbid = null;
        $add = function (string $relation, string $label, string $target, array $entity, ?string $artist = null) use (&$items) {
            if (!isset($entity['id'], $entity['title'])) {
                return;
            }
            $items[$relation.'|'.$entity['id']] ??= [
                'relation' => $relation, 'label' => $label, 'target' => $target, 'mbid' => $entity['id'], 'title' => $entity['title'],
                'artist' => $artist, 'detail' => ($entity['disambiguation'] ?? '') !== '' ? $entity['disambiguation'] : null,
            ];
        };

        foreach ($recording['relations'] ?? [] as $relation) {
            $type = $relation['type'] ?? '';
            $direction = ($relation['direction'] ?? 'forward') === 'backward' ? 'backward' : 'forward';

            if (($relation['target-type'] ?? null) === 'recording' && isset(self::LABELS[$type])) {
                $add(str_replace(' ', '_', $type), self::LABELS[$type][$direction], 'recording', $relation['recording'] ?? []);

                continue;
            }

            if (($relation['target-type'] ?? null) !== 'work' || $type !== 'performance') {
                continue;
            }

            $work = $relation['work'] ?? [];
            $workMbid ??= $work['id'] ?? null;
            $writers = collect($work['relations'] ?? [])
                ->filter(fn ($r) => ($r['target-type'] ?? null) === 'artist' && in_array($r['type'] ?? '', self::WRITER_ROLES, true))
                ->map(fn ($r) => $r['artist']['name'] ?? null)->filter()->unique()->implode(', ');

            // The "cover" attribute of a performance says this recording is a cover of the work (written by someone).
            if (in_array('cover', $relation['attributes'] ?? [], true)) {
                $add('cover_of', 'Cover of', 'work', $work, $writers !== '' ? $writers : null);
            }

            foreach ($work['relations'] ?? [] as $workRelation) {
                $workType = $workRelation['type'] ?? '';
                if (($workRelation['target-type'] ?? null) === 'work' && isset(self::WORK_LABELS[$workType])) {
                    $direction = ($workRelation['direction'] ?? 'forward') === 'backward' ? 'backward' : 'forward';
                    $add(str_replace(' ', '_', $workType), self::WORK_LABELS[$workType][$direction], 'work', $workRelation['work'] ?? []);
                }
            }
        }

        return ['work_mbid' => $workMbid, 'items' => array_slice(array_values($items), 0, self::MAX_ITEMS)];
    }

    /** Stores what `parse()` found as the track's metadata; an empty result removes an older one. */
    public static function save(Track $track, array $parsed): void
    {
        $save = Enrichment::saver($track, self::SOURCE);
        $save('work_mbid', $parsed['work_mbid']);

        if ($parsed['items'] !== []) {
            Enrichment::save($track, 'related_works', json_encode($parsed['items'], JSON_UNESCAPED_UNICODE), 'json', self::SOURCE);
        } else {
            $track->metadata()->where('key', 'related_works')->where('source', self::SOURCE)->delete();
        }
    }

    /**
     * The related items of a track, each with the library track it is when the library has it, and the other library
     * tracks that perform the same work.
     *
     * @param  list<string>  $hidden  sources hidden by scope and client (LibrarySources::hiddenForRequest)
     * @return array{related: list<array<string, mixed>>, versions: list<array<string, mixed>>}
     */
    public static function for(Track $track, array $hidden = []): array
    {
        $items = json_decode((string) $track->metadata()->where('key', 'related_works')->where('source', self::SOURCE)->value('value'), true);
        $items = is_array($items) ? $items : [];
        $workMbid = $track->metadata()->where('key', 'work_mbid')->where('source', self::SOURCE)->value('value');

        $recordings = array_column(array_filter($items, fn ($i) => $i['target'] === 'recording'), 'mbid');
        $works = array_column(array_filter($items, fn ($i) => $i['target'] === 'work'), 'mbid');

        $owned = fn (string $key, array $values, array $sources) => $values === [] ? collect() : Metadata::query()
            ->where('metadatable_type', $track->getMorphClass())->where('key', $key)->whereIn('source', $sources)->whereIn('value', $values)
            ->where('metadatable_id', '!=', $track->id)->get(['metadatable_id', 'value'])
            ->groupBy('value')->map(fn ($rows) => $rows->pluck('metadatable_id')->unique()->values()->all());

        $byRecording = $owned('mbid', $recordings, [Enrichment::MUSICBRAINZ, Enrichment::ACOUSTID, 'local']);
        $byWork = $owned('work_mbid', array_values(array_filter([...$works, $workMbid])), [self::SOURCE]);

        $ids = collect([$byRecording, $byWork])->flatMap(fn ($m) => $m->flatten())->unique()->values();
        $tracks = $ids->isEmpty() ? collect() : LibrarySources::tracks(Track::query(), $hidden)->with(['artist', 'album'])->whereIn('tracks.id', $ids)->get()->keyBy('id');

        $first = fn ($map, string $mbid) => ($id = collect($map[$mbid] ?? [])->first(fn ($i) => isset($tracks[$i]))) ? ApiItems::track($tracks[$id]) : null;

        return [
            'related' => array_map(fn ($i) => $i + ['track' => $first($i['target'] === 'recording' ? $byRecording : $byWork, $i['mbid'])], $items),
            'versions' => collect($byWork[$workMbid] ?? [])->filter(fn ($id) => isset($tracks[$id]))->map(fn ($id) => ApiItems::track($tracks[$id]))->values()->all(),
        ];
    }
}
