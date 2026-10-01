<?php

namespace App\Domain\Library;

use App\Domain\Artwork\ArtworkCache;
use App\Domain\Artwork\LibraryArtwork;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Play;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Merges library records that LibraryIdentity considers the same (see
 * docs/architecture/library-identity.md), for `library:merge-duplicates`.
 *
 * plan() works on freshly computed keys in memory and changes nothing;
 * merge() runs a plan, one transaction per group: albums first, then
 * artists, then tracks. The oldest record (lowest id) of a group survives;
 * everything that pointed at the others is re-pointed to it, then they are
 * deleted. Running it again finds nothing more to merge.
 */
final class LibraryMerger
{
    /**
     * Groups to merge, as [survivor id, [loser ids]] per kind. Artist groups
     * come first because albums and tracks are grouped under the artist (and
     * album) they will end up on.
     *
     * @return array{artists: list<array{0: int, 1: list<int>}>, albums: list<array{0: int, 1: list<int>}>, tracks: list<array{0: int, 1: list<int>}>}
     */
    public function plan(): array
    {
        $artists = Artist::query()->orderBy('id')->get(['id', 'name']);
        [$artistGroups, $artistOf] = $this->group($artists, fn (Artist $a) => Normalizer::artist($a->name));

        $albums = Album::query()->orderBy('id')->get(['id', 'artist_id', 'name']);
        [$albumGroups, $albumOf] = $this->group($albums, fn (Album $a) => ($artistOf[$a->artist_id] ?? $a->artist_id).'|'.Normalizer::album($a->name));

        $tracks = Track::query()->orderBy('id')->get(['id', 'album_id', 'artist_id', 'name', 'duration']);

        return [
            'artists' => $artistGroups,
            'albums' => $albumGroups,
            'tracks' => $this->trackGroups($tracks, $artistOf, $albumOf),
        ];
    }

    /**
     * @param  array{artists: list<array{0: int, 1: list<int>}>, albums: list<array{0: int, 1: list<int>}>, tracks: list<array{0: int, 1: list<int>}>}  $plan
     * @return array{artists: int, albums: int, tracks: int} records merged away
     */
    public function merge(array $plan): array
    {
        $merged = ['artists' => 0, 'albums' => 0, 'tracks' => 0];

        foreach ($plan['albums'] as [$survivor, $losers]) {
            $merged['albums'] += DB::transaction(fn () => $this->mergeAlbums($survivor, $losers));
        }

        foreach ($plan['artists'] as [$survivor, $losers]) {
            $merged['artists'] += DB::transaction(fn () => $this->mergeArtists($survivor, $losers));
        }

        foreach ($plan['tracks'] as [$survivor, $losers]) {
            $merged['tracks'] += DB::transaction(fn () => $this->mergeTracks($survivor, $losers));
        }

        return $merged;
    }

    /** Recomputes every `name_key` (e.g. after a Normalizer::VERSION bump); returns the rows changed. */
    public function rekey(): int
    {
        $changed = 0;

        foreach ([Artist::class, Album::class, Track::class] as $model) {
            $model::query()->select('id', 'name', 'name_key')->chunkById(500, function ($rows) use ($model, &$changed) {
                foreach ($rows as $row) {
                    $key = LibraryIdentity::keyFor($model, $row->name);
                    if ($row->name_key !== $key) {
                        $model::query()->whereKey($row->id)->update(['name_key' => $key]);
                        $changed++;
                    }
                }
            });
        }

        return $changed;
    }

    /**
     * @param  list<int>  $loserIds
     * @return int albums merged away
     */
    public function mergeAlbums(int $survivorId, array $loserIds): int
    {
        $survivor = Album::find($survivorId);
        $losers = Album::query()->whereKey($loserIds)->whereKeyNot($survivorId)->orderBy('id')->get();

        if ($survivor === null || $losers->isEmpty()) {
            return 0;
        }

        $cover = $this->bestCover($survivor, $losers);
        if ($cover !== $survivor) {
            $survivor->images = $cover->images;
            $survivor->colors = $cover->colors;
        }

        foreach ($losers as $loser) {
            Track::query()->where('album_id', $loser->id)->update(['album_id' => $survivor->id]);
            $this->mergeMetadata($loser, $survivor);
            $survivor->favorited_at = $this->earliest($survivor->favorited_at, $loser->favorited_at);
            $survivor->released_at ??= $loser->released_at;
            $loser->delete();
        }

        $survivor->save();

        return $losers->count();
    }

    /**
     * @param  list<int>  $loserIds
     * @return int artists merged away
     */
    public function mergeArtists(int $survivorId, array $loserIds): int
    {
        $survivor = Artist::find($survivorId);
        $losers = Artist::query()->whereKey($loserIds)->whereKeyNot($survivorId)->orderBy('id')->get();

        if ($survivor === null || $losers->isEmpty()) {
            return 0;
        }

        foreach ($losers as $loser) {
            foreach (Album::query()->where('artist_id', $loser->id)->get() as $album) {
                // albums is unique on (artist_id, name, source): fold a clash in instead of moving it.
                $clash = Album::query()->where('artist_id', $survivor->id)
                    ->where('name', $album->name)->where('source', $album->source)->first();

                if ($clash !== null) {
                    $this->mergeAlbums($clash->id, [$album->id]);
                } else {
                    $album->update(['artist_id' => $survivor->id]);
                }
            }

            Track::query()->where('artist_id', $loser->id)->update(['artist_id' => $survivor->id]);
            $this->mergeMetadata($loser, $survivor);
            $survivor->favorited_at = $this->earliest($survivor->favorited_at, $loser->favorited_at);
            $loser->delete();
        }

        $survivor->save();

        return $losers->count();
    }

    /**
     * @param  list<int>  $loserIds
     * @return int tracks merged away
     */
    public function mergeTracks(int $survivorId, array $loserIds): int
    {
        $survivor = Track::find($survivorId);
        $losers = Track::query()->whereKey($loserIds)->whereKeyNot($survivorId)->orderBy('id')->get();

        if ($survivor === null || $losers->isEmpty()) {
            return 0;
        }

        $adopt = null;

        foreach ($losers as $loser) {
            Play::query()->where('track_id', $loser->id)->update(['track_id' => $survivor->id]);
            $this->mergePlaylistEntries($loser->id, $survivor->id);
            $this->mergeMetadata($loser, $survivor);

            $survivor->album_id ??= $loser->album_id;
            $survivor->duration = $survivor->duration ?: $loser->duration;
            $survivor->images = $survivor->images ?: $loser->images;

            if ($loser->external_id !== null && $loser->external_id !== '') {
                if (($survivor->external_id === null || $survivor->external_id === '') && $adopt === null) {
                    // Taken over after the loser is deleted (tracks is unique on external_id + source).
                    $adopt = [$loser->external_id, $loser->source];
                } else {
                    LibraryIdentity::keepExternalId($survivor, $loser->external_id, $loser->source);
                }
            }

            $loser->delete();
        }

        if ($adopt !== null) {
            [$survivor->external_id, $survivor->source] = $adopt;
        }

        $survivor->save();

        return $losers->count();
    }

    /**
     * Groups $rows by $key: [[survivor id, [loser ids]], …] for groups of
     * two or more, and id => survivor id for every row.
     *
     * @return array{0: list<array{0: int, 1: list<int>}>, 1: array<int, int>}
     */
    private function group(Collection $rows, \Closure $key): array
    {
        $groups = [];
        $survivorOf = [];

        foreach ($rows->groupBy($key) as $members) {
            $ids = $members->pluck('id')->sort()->values()->all();
            foreach ($ids as $id) {
                $survivorOf[$id] = $ids[0];
            }
            if (count($ids) > 1) {
                $groups[] = [$ids[0], array_slice($ids, 1)];
            }
        }

        return [$groups, $survivorOf];
    }

    /**
     * The track rules of LibraryIdentity::match(): same album (after album
     * merges) + key + compatible duration; an album-less track goes to the
     * artist's oldest compatible album track, else to the oldest compatible
     * album-less one.
     *
     * @param  array<int, int>  $artistOf
     * @param  array<int, int>  $albumOf
     * @return list<array{0: int, 1: list<int>}>
     */
    private function trackGroups(Collection $tracks, array $artistOf, array $albumOf): array
    {
        /** @var array<string, list<array{head: Track, ids: list<int>}>> $clusters */
        $clusters = [];
        $albumHeads = [];

        $place = function (string $bucket, Track $track) use (&$clusters): array {
            foreach ($clusters[$bucket] ?? [] as $i => $cluster) {
                if (LibraryIdentity::sameDuration($cluster['head']->duration, $track->duration)) {
                    $clusters[$bucket][$i]['ids'][] = $track->id;

                    return $clusters[$bucket][$i];
                }
            }

            $clusters[$bucket][] = ['head' => $track, 'ids' => [$track->id]];

            return end($clusters[$bucket]);
        };

        $key = fn (Track $t) => Normalizer::track($t->name);
        $artist = fn (Track $t) => $artistOf[$t->artist_id] ?? $t->artist_id;

        foreach ($tracks->whereNotNull('album_id') as $track) {
            $cluster = $place('album:'.($albumOf[$track->album_id] ?? $track->album_id).'|'.$key($track), $track);
            if (count($cluster['ids']) === 1) {
                $albumHeads[$artist($track).'|'.$key($track)][] = $track;
            }
        }

        foreach ($tracks->whereNull('album_id') as $track) {
            $head = collect($albumHeads[$artist($track).'|'.$key($track)] ?? [])
                ->first(fn (Track $h) => LibraryIdentity::sameDuration($h->duration, $track->duration));

            if ($head !== null) {
                $bucket = 'album:'.($albumOf[$head->album_id] ?? $head->album_id).'|'.$key($head);
                foreach ($clusters[$bucket] as $i => $cluster) {
                    if ($cluster['head']->id === $head->id) {
                        $clusters[$bucket][$i]['ids'][] = $track->id;
                    }
                }
            } else {
                $place('artist:'.$artist($track).'|'.$key($track), $track);
            }
        }

        $groups = [];
        foreach ($clusters as $bucket) {
            foreach ($bucket as $cluster) {
                if (count($cluster['ids']) > 1) {
                    $ids = $cluster['ids'];
                    sort($ids);
                    $groups[] = [$cluster['head']->id, array_values(array_diff($ids, [$cluster['head']->id]))];
                }
            }
        }

        return $groups;
    }

    /**
     * Moves $from's metadata to $to. Where $to already has the key from the
     * same source (for `external_id`: the same value), $to's row is kept,
     * taking $from's value only when its own is empty.
     */
    private function mergeMetadata(Model $from, Model $to): void
    {
        $existing = Metadata::query()
            ->where('metadatable_type', $to->getMorphClass())
            ->where('metadatable_id', $to->getKey())
            ->get();

        $rows = Metadata::query()
            ->where('metadatable_type', $from->getMorphClass())
            ->where('metadatable_id', $from->getKey())
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $same = $existing->first(fn (Metadata $m) => $m->key === $row->key
                && $m->source === $row->source
                && ($row->key !== 'external_id' || $m->value === $row->value));

            if ($same === null) {
                $row->update(['metadatable_id' => $to->getKey()]);
                $existing->push($row);

                continue;
            }

            if (($same->value === null || $same->value === '') && $row->value !== null && $row->value !== '') {
                $same->update(['value' => $row->value, 'type' => $row->type]);
            }

            $row->delete();
        }
    }

    /** Re-points playlist entries; a playlist that already has the survivor keeps its own entry. */
    private function mergePlaylistEntries(int $fromId, int $toId): void
    {
        $taken = DB::table('playlist_track')->where('track_id', $toId)->pluck('playlist_id');

        DB::table('playlist_track')->where('track_id', $fromId)->whereIn('playlist_id', $taken)->delete();
        DB::table('playlist_track')->where('track_id', $fromId)->update(['track_id' => $toId]);
    }

    /** The album whose cover is fully processed, else the first with a cover, else $survivor. */
    private function bestCover(Album $survivor, Collection $losers): Album
    {
        $all = collect([$survivor])->concat($losers);
        $url = fn (Album $a) => LibraryArtwork::coverUrl($a->images);

        return $all->first(fn (Album $a) => ($u = $url($a)) !== null && ArtworkCache::has($u))
            ?? $all->first(fn (Album $a) => $url($a) !== null)
            ?? $survivor;
    }

    private function earliest(mixed $a, mixed $b): mixed
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->lessThanOrEqualTo($b) ? $a : $b;
    }
}
