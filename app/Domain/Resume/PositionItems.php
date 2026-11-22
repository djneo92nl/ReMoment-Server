<?php

namespace App\Domain\Resume;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Podcasts\PodcastArtwork;
use App\Domain\Profile\Profile;
use App\Models\Media\Track;
use App\Models\PlaybackPosition;
use App\Models\PodcastEpisode;

/** The items behind the bookmarks, as the "continue listening" list of the API and the web UI shows them. */
final class PositionItems
{
    public static function exists(string $kind, int $id): bool
    {
        return match ($kind) {
            PlaybackPosition::TRACK => Track::whereKey($id)->exists(),
            PlaybackPosition::EPISODE => PodcastEpisode::whereKey($id)->exists(),
            default => false,
        };
    }

    public static function duration(string $kind, int $id): ?int
    {
        return match ($kind) {
            PlaybackPosition::TRACK => Track::whereKey($id)->value('duration') ?: null,
            PlaybackPosition::EPISODE => PodcastEpisode::whereKey($id)->value('duration') ?: null,
            default => null,
        };
    }

    /**
     * @return list<array{kind: string, id: int, position: int, duration: ?int, percent: ?int, updated_at: string, title: string, subtitle: ?string, album_id: ?int, artwork: mixed}>
     */
    public static function recent(int $limit = 30): array
    {
        $positions = PlaybackPosition::query()->where('position', '>', 0)->latest('updated_at')->limit($limit)->get();
        $tracks = Track::with(['artist', 'album'])->whereIn('id', $positions->where('kind', PlaybackPosition::TRACK)->pluck('item_id'))->get()->keyBy('id');
        $episodes = Profile::allows('podcasts')
            ? PodcastEpisode::with('podcast')->whereIn('id', $positions->where('kind', PlaybackPosition::EPISODE)->pluck('item_id'))->get()->keyBy('id')
            : collect();

        return $positions->map(function (PlaybackPosition $p) use ($tracks, $episodes) {
            if ($p->kind === PlaybackPosition::EPISODE) {
                return ($episode = $episodes->get($p->item_id)) ? self::episodeRow($p, $episode) : null;
            }

            $track = $p->kind === PlaybackPosition::TRACK ? $tracks->get($p->item_id) : null;
            if ($track === null) {
                return null; // the item is gone (or its feature): the bookmark is left alone but not listed
            }

            return [
                'kind' => $p->kind,
                'id' => $p->item_id,
                'position' => $p->position,
                'duration' => $p->duration,
                'percent' => $p->duration ? (int) min(100, round($p->position / $p->duration * 100)) : null,
                'updated_at' => $p->updated_at->toIso8601String(),
                'title' => $track->name,
                'subtitle' => trim(($track->artist?->name ?? '').($track->album ? ' · '.$track->album->name : ''), ' ·') ?: null,
                'album_id' => $track->album_id,
                'artwork' => LibraryItemArtwork::forImages($track->album?->images),
            ];
        })->filter()->values()->all();
    }

    /** A podcast episode as a "continue listening" row: the podcast is the subtitle and its cover the artwork. */
    private static function episodeRow(PlaybackPosition $p, PodcastEpisode $episode): array
    {
        return [
            'kind' => $p->kind,
            'id' => $p->item_id,
            'position' => $p->position,
            'duration' => $p->duration ?? $episode->duration,
            'percent' => ($duration = $p->duration ?? $episode->duration) ? (int) min(100, round($p->position / $duration * 100)) : null,
            'updated_at' => $p->updated_at->toIso8601String(),
            'title' => $episode->title,
            'subtitle' => $episode->podcast?->title,
            'album_id' => null,
            'artwork' => PodcastArtwork::resolve($episode->image_url ?: $episode->podcast?->image_url),
        ];
    }
}
