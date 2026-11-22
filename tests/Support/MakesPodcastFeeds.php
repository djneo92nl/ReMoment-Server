<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/** RSS fixtures for the podcast tests: nothing here touches the network (Http::fake). */
trait MakesPodcastFeeds
{
    /**
     * @param  list<array<string, mixed>>  $episodes  title, guid, date, duration, url, type (null = no enclosure)
     */
    protected function feedXml(string $title = 'The Show', array $episodes = [], array $channel = []): string
    {
        $items = '';
        foreach ($episodes as $i => $e) {
            $n = $i + 1;
            $url = array_key_exists('url', $e) ? $e['url'] : "https://cdn.test/{$n}.mp3";
            $enclosure = $url ? '<enclosure url="'.$url.'" length="1234" type="'.($e['type'] ?? 'audio/mpeg').'"/>' : '';
            $items .= '<item><title>'.($e['title'] ?? "Episode {$n}").'</title>'
                .'<guid isPermaLink="false">'.($e['guid'] ?? "guid-{$n}").'</guid>'
                .'<pubDate>'.($e['date'] ?? \Carbon\Carbon::create(2026, 11, min($n, 28), 8)->toRssString()).'</pubDate>'
                .'<description><![CDATA[<p>About <b>episode</b> '.$n.' &amp; more</p>]]></description>'
                .$enclosure
                .'<itunes:duration>'.($e['duration'] ?? '1:00:00').'</itunes:duration></item>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel>'
            .'<title>'.$title.'</title><link>https://show.test</link><language>en</language>'
            .'<description>'.($channel['description'] ?? 'A show about things').'</description>'
            .'<itunes:author>'.($channel['author'] ?? 'Jane Host').'</itunes:author>'
            .'<itunes:image href="'.($channel['image'] ?? 'https://show.test/cover.jpg').'"/>'
            .$items.'</channel></rss>';
    }

    /** @var array<string, array{0: string, 1: array<string, string>, 2: int}> the current answer per feed address */
    private array $feedAnswers = [];

    /** Answers a GET of this address with the feed; calling it again changes the answer (a 304 later, a new episode). */
    protected function fakeFeed(string $url, string $xml, array $headers = [], int $status = 200): void
    {
        $first = $this->feedAnswers === [];
        $this->feedAnswers[$url] = [$xml, $headers, $status];

        if ($first) {
            Http::fake(function ($request) {
                [$xml, $headers, $status] = $this->feedAnswers[$request->url()] ?? ['', [], 404];

                return Http::response($xml, $status, $headers);
            });
        }
    }
}
