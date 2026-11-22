<?php

namespace App\Domain\Podcasts;

use Carbon\Carbon;
use SimpleXMLElement;

/**
 * Reads an RSS 2.0 podcast feed (with the iTunes tags most feeds carry) into plain arrays. No network here: the
 * refresher fetches, this only parses. Items without an audio enclosure are not episodes and are left out.
 */
final class FeedParser
{
    private const ITUNES = 'http://www.itunes.com/dtds/podcast-1.0.dtd';

    private const ATOM = 'http://www.w3.org/2005/Atom';

    /**
     * @return array{title: string, author: ?string, description: ?string, site_url: ?string, image_url: ?string, language: ?string, episodes: list<array<string, mixed>>}
     *
     * @throws InvalidFeedException
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($feed === false || $feed->getName() !== 'rss' || !isset($feed->channel)) {
            throw new InvalidFeedException('That address is not an RSS podcast feed.');
        }

        $channel = $feed->channel;
        $itunes = $channel->children(self::ITUNES);
        $title = self::text($channel->title);

        if ($title === '') {
            throw new InvalidFeedException('The feed has no title.');
        }

        $image = self::attr($itunes->image, 'href') ?? self::text($channel->image->url);

        $episodes = [];
        foreach ($channel->item as $item) {
            if ($episode = self::episode($item)) {
                $episodes[] = $episode;
            }
        }

        return [
            'title' => mb_substr($title, 0, 255),
            'author' => self::limit(self::text($itunes->author) ?: self::text($channel->children(self::ATOM)->author->name ?? null), 255),
            'description' => self::limit(self::plain(self::text($channel->description) ?: self::text($itunes->summary)), 5000),
            'site_url' => self::url(self::text($channel->link)),
            'image_url' => self::url($image),
            'language' => self::limit(self::text($channel->language), 16),
            'episodes' => $episodes,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function episode(SimpleXMLElement $item): ?array
    {
        $enclosure = $item->enclosure;
        $url = self::url(self::attr($enclosure, 'url'));
        $type = self::attr($enclosure, 'type');

        if ($url === null || ($type !== null && !str_starts_with(strtolower($type), 'audio/') && !str_contains(strtolower($type), 'ogg'))) {
            return null;
        }

        $itunes = $item->children(self::ITUNES);
        $title = self::text($item->title) ?: self::text($itunes->title);
        $guid = self::text($item->guid) ?: $url;

        return [
            'guid' => $guid,
            'title' => mb_substr($title !== '' ? $title : 'Untitled episode', 0, 500),
            'description' => self::limit(self::plain(self::text($item->description) ?: self::text($itunes->summary)), 5000),
            'enclosure_url' => $url,
            'mime' => self::limit($type, 100),
            'size' => ($length = (int) self::attr($enclosure, 'length')) > 0 ? $length : null,
            'duration' => self::duration(self::text($itunes->duration)),
            'image_url' => self::url(self::attr($itunes->image, 'href')),
            'season' => ($season = (int) self::text($itunes->season)) > 0 ? min($season, 65535) : null,
            'number' => ($number = (int) self::text($itunes->episode)) > 0 ? min($number, 65535) : null,
            'published_at' => self::date(self::text($item->pubDate)),
        ];
    }

    /** `3600`, `59:03` and `1:02:03` to seconds; anything else is unknown. */
    public static function duration(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || !preg_match('/^\d+(:\d{1,2}){0,2}$/', $value)) {
            return null;
        }

        $seconds = 0;
        foreach (explode(':', $value) as $part) {
            $seconds = $seconds * 60 + (int) $part;
        }

        return $seconds > 0 ? $seconds : null;
    }

    private static function date(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function text(mixed $node): string
    {
        return $node === null ? '' : trim((string) $node);
    }

    private static function attr(mixed $node, string $name): ?string
    {
        // attributes() with no namespace: an element reached through children($ns) would look the attribute up in that namespace.
        $attributes = $node instanceof SimpleXMLElement ? $node->attributes() : null;

        if ($attributes === null || !isset($attributes[$name])) {
            return null;
        }

        $value = trim((string) $attributes[$name]);

        return $value === '' ? null : $value;
    }

    /** Only http and https addresses are kept: the rest (javascript:, file:) is never stored. */
    private static function url(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('#^https?://[^\s]+$#i', $value) ? $value : null;
    }

    private static function plain(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private static function limit(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
