<?php

namespace App\Domain\Podcasts;

use App\Models\PlaybackPosition;
use App\Models\Podcast;
use App\Models\PodcastEpisode;

/**
 * Subscriptions and episode state, shared by the REST API, MCP and the /library/podcasts page (workstream 8.2).
 * Household-wide: there are no accounts, so "played" is the household's.
 */
final class Podcasts
{
    /** The address as stored: trimmed, `feed://` and `itpc://` read as http. Anything but http(s) is refused. */
    public static function normaliseUrl(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#^(feed|itpc|pcast)://#i', 'http://', $url) ?? $url;

        if (!preg_match('#^https?://[^\s/]+[^\s]*$#i', $url) || strlen($url) > 2000) {
            throw new InvalidFeedException('Give the RSS address of the podcast (http or https).');
        }

        return $url;
    }

    /**
     * Subscribes to a feed: it is fetched once now, so a wrong address is refused at once and the first episodes are there.
     *
     * @return array{0: Podcast, 1: bool} the podcast and whether it was new
     *
     * @throws InvalidFeedException
     */
    public static function subscribe(string $url): array
    {
        $url = self::normaliseUrl($url);

        if ($existing = Podcast::where('feed_hash', sha1($url))->first()) {
            return [$existing, false];
        }

        [$parsed, $response] = PodcastRefresher::load($url);

        $podcast = Podcast::create(['feed_hash' => sha1($url), 'feed_url' => $url, 'title' => $parsed['title']]);
        PodcastRefresher::store($podcast, $parsed, $response);

        return [$podcast->refresh(), true];
    }

    /** Removes the subscription with its episodes and their bookmarks. */
    public static function unsubscribe(Podcast $podcast): void
    {
        PlaybackPosition::where('kind', PlaybackPosition::EPISODE)->whereIn('item_id', $podcast->episodes()->select('id'))->delete();
        $podcast->delete();
    }

    public static function markPlayed(PodcastEpisode $episode, bool $played = true): void
    {
        $episode->forceFill(['played_at' => $played ? ($episode->played_at ?? now()) : null])->save();

        if ($played) {
            PlaybackPosition::where('kind', PlaybackPosition::EPISODE)->where('item_id', $episode->id)->delete();
        }
    }

    /** Marks every episode of the podcast played; returns how many changed. */
    public static function markAllPlayed(Podcast $podcast): int
    {
        PlaybackPosition::where('kind', PlaybackPosition::EPISODE)->whereIn('item_id', $podcast->episodes()->select('id'))->delete();

        return $podcast->episodes()->whereNull('played_at')->update(['played_at' => now()]);
    }

    /** An episode played to its end (a device's progress, or a position set at the end): played, the bookmark is already gone. */
    public static function episodeFinished(int $episodeId): void
    {
        PodcastEpisode::whereKey($episodeId)->whereNull('played_at')->update(['played_at' => now()]);
    }

    /** Episodes, newest first, for a podcast or all of them (the "new episodes" list), keyset-paged on (published_at, id). */
    public static function episodes(?Podcast $podcast, bool $unplayedOnly, int $limit, ?string $cursor): array
    {
        $query = PodcastEpisode::query()->with('podcast');
        $podcast && $query->where('podcast_id', $podcast->id);
        $unplayedOnly && $query->whereNull('played_at');

        if ($cursor !== null && $cursor !== '') {
            $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

            if (!is_array($decoded) || !isset($decoded[0], $decoded[1]) || !is_int($decoded[0]) || !is_int($decoded[1])) {
                throw new \InvalidArgumentException('Bad cursor.');
            }

            [$at, $id] = $decoded;
            $query->where(fn ($q) => $q->where('published_at', '<', date('Y-m-d H:i:s', $at))
                ->orWhere(fn ($q) => $q->where('published_at', date('Y-m-d H:i:s', $at))->where('id', '<', $id)));
        }

        $rows = $query->orderByDesc('published_at')->orderByDesc('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $positions = PlaybackPosition::where('kind', PlaybackPosition::EPISODE)->whereIn('item_id', $rows->pluck('id'))->pluck('position', 'item_id');
        $last = $rows->last();
        $next = $more && $last?->published_at ? rtrim(strtr(base64_encode(json_encode([$last->published_at->timestamp, $last->id])), '+/', '-_'), '=') : null;

        return [
            'data' => $rows->map(fn (PodcastEpisode $e) => $e->apiItem($positions[$e->id] ?? null))->values()->all(),
            'next_cursor' => $next,
        ];
    }
}
