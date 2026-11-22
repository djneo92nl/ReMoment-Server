<?php

namespace App\Domain\Podcasts;

use App\Models\Podcast;
use App\Models\PodcastEpisode;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fetches one feed and brings the podcast and its episodes up to date (workstream 8.2, docs/api/podcasts.md).
 *
 * Polite on purpose: a conditional GET (ETag / Last-Modified, a 304 costs the publisher nothing), a minimum interval per
 * feed, a doubling back-off while a feed fails, and `Retry-After` honoured on 429/503. Nothing here ever deletes an
 * episode the household has a bookmark for.
 */
final class PodcastRefresher
{
    /** What a refresh did: `updated`, `unchanged` (304 or same content), `skipped` (not due yet) or `failed`. */
    public static function refresh(Podcast $podcast, bool $force = false): string
    {
        if (!$force && $podcast->next_check_at !== null && $podcast->next_check_at->isFuture()) {
            return 'skipped';
        }

        try {
            $response = self::fetch($podcast);
        } catch (\Throwable $e) {
            return self::fail($podcast, 'Could not reach the feed: '.$e->getMessage());
        }

        if ($response->status() === 304) {
            $podcast->forceFill(['last_checked_at' => now(), 'failures' => 0, 'last_error' => null, 'next_check_at' => self::nextCheck()])->save();

            return 'unchanged';
        }

        if (in_array($response->status(), [429, 503], true) && ($wait = (int) $response->header('Retry-After')) > 0) {
            return self::fail($podcast, 'The publisher asked to wait (HTTP '.$response->status().').', now()->addSeconds(min($wait, 86400)));
        }

        if (!$response->successful()) {
            return self::fail($podcast, 'The feed answered HTTP '.$response->status().'.');
        }

        try {
            $parsed = FeedParser::parse(self::body($response));
        } catch (InvalidFeedException $e) {
            return self::fail($podcast, $e->getMessage());
        }

        self::store($podcast, $parsed, $response);

        return 'updated';
    }

    /** The first fetch of a feed address (subscribing): never conditional, errors are thrown to the caller. */
    public static function load(string $url): array
    {
        try {
            $response = self::request($url, []);
        } catch (\Throwable $e) {
            throw new InvalidFeedException('Could not reach the feed: '.$e->getMessage());
        }

        if (!$response->successful()) {
            throw new InvalidFeedException('The feed answered HTTP '.$response->status().'.');
        }

        return [FeedParser::parse(self::body($response)), $response];
    }

    /** Writes a parsed feed: the podcast's own fields, new and changed episodes, retention. */
    public static function store(Podcast $podcast, array $parsed, ?Response $response = null): void
    {
        $keep = PodcastRetention::limit($podcast);
        $episodes = collect($parsed['episodes'])
            ->unique('guid')
            ->sortByDesc(fn (array $e) => $e['published_at']?->timestamp ?? 0)
            ->when($keep > 0, fn ($c) => $c->take($keep)); // what retention would prune is not stored just to come back

        $podcast->forceFill([
            'title' => $parsed['title'],
            'author' => $parsed['author'],
            'description' => $parsed['description'],
            'site_url' => $parsed['site_url'],
            'image_url' => $parsed['image_url'],
            'language' => $parsed['language'],
            'etag' => $response?->header('ETag') ?: null,
            'last_modified' => $response?->header('Last-Modified') ?: null,
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'next_check_at' => self::nextCheck(),
            'failures' => 0,
            'last_error' => null,
        ])->save();

        $known = $podcast->episodes()->get(['id', 'guid_hash'])->pluck('id', 'guid_hash');

        foreach ($episodes as $e) {
            $hash = sha1($e['guid']);
            $values = $e + ['guid_hash' => $hash];

            if ($id = $known[$hash] ?? null) {
                // Played state is ours: a feed edit never touches it.
                PodcastEpisode::whereKey($id)->update(collect($values)->except(['guid_hash', 'guid'])->filter(fn ($v, $k) => $k !== 'published_at' || $v !== null)->all());
            } else {
                // An episode with no date in the feed counts from when it was first seen, so lists and cursors always have one.
                $podcast->episodes()->create(['published_at' => $values['published_at'] ?? now()] + $values);
            }
        }

        PodcastRetention::apply($podcast);
    }

    private static function fetch(Podcast $podcast): Response
    {
        $headers = array_filter([
            'If-None-Match' => $podcast->etag,
            'If-Modified-Since' => $podcast->last_modified,
        ]);

        return self::request($podcast->feed_url, $headers);
    }

    private static function request(string $url, array $headers): Response
    {
        return Http::timeout((int) config('podcasts.timeout'))
            ->withHeaders($headers + ['User-Agent' => config('podcasts.user_agent'), 'Accept' => 'application/rss+xml, application/xml, text/xml;q=0.9, */*;q=0.5'])
            ->get($url);
    }

    private static function body(Response $response): string
    {
        $body = $response->body();

        if (strlen($body) > (int) config('podcasts.max_bytes')) {
            throw new InvalidFeedException('The feed is larger than '.round(config('podcasts.max_bytes') / 1048576).' MB.');
        }

        return $body;
    }

    private static function fail(Podcast $podcast, string $message, ?\DateTimeInterface $retryAt = null): string
    {
        $failures = $podcast->failures + 1;
        $minutes = min((int) config('podcasts.refresh_interval') * (2 ** min($failures, 10)), (int) config('podcasts.max_backoff_hours') * 60);

        $podcast->forceFill([
            'failures' => $failures,
            'last_error' => mb_substr($message, 0, 255),
            'last_checked_at' => now(),
            'next_check_at' => $retryAt ?? now()->addMinutes($minutes),
        ])->save();

        return 'failed';
    }

    private static function nextCheck(): \DateTimeInterface
    {
        return now()->addMinutes(max(5, (int) config('podcasts.refresh_interval')));
    }
}
