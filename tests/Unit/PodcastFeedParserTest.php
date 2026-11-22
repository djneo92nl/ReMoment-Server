<?php

namespace Tests\Unit;

use App\Domain\Podcasts\FeedParser;
use App\Domain\Podcasts\InvalidFeedException;
use PHPUnit\Framework\TestCase;
use Tests\Support\MakesPodcastFeeds;

class PodcastFeedParserTest extends TestCase
{
    use MakesPodcastFeeds;

    public function test_a_feed_is_read_into_the_podcast_and_its_episodes(): void
    {
        $parsed = FeedParser::parse($this->feedXml('The Show', [
            ['title' => 'One', 'guid' => 'g1', 'duration' => '1:02:03'],
            ['title' => 'Two', 'guid' => 'g2', 'duration' => '59:03'],
            ['title' => 'Three', 'guid' => 'g3', 'duration' => '120'],
        ]));

        $this->assertSame('The Show', $parsed['title']);
        $this->assertSame('Jane Host', $parsed['author']);
        $this->assertSame('https://show.test/cover.jpg', $parsed['image_url']);
        $this->assertSame('https://show.test', $parsed['site_url']);
        $this->assertCount(3, $parsed['episodes']);
        $this->assertSame([3723, 3543, 120], array_column($parsed['episodes'], 'duration'));
        $this->assertSame('g1', $parsed['episodes'][0]['guid']);
        $this->assertSame('About episode 1 & more', $parsed['episodes'][0]['description']);
        $this->assertSame('https://cdn.test/1.mp3', $parsed['episodes'][0]['enclosure_url']);
        $this->assertSame(1234, $parsed['episodes'][0]['size']);
        $this->assertSame('2026-11-01', $parsed['episodes'][0]['published_at']->toDateString());
    }

    public function test_items_without_audio_are_not_episodes_and_unsafe_urls_are_dropped(): void
    {
        $parsed = FeedParser::parse($this->feedXml('Show', [
            ['title' => 'Text only', 'url' => null],
            ['title' => 'Video', 'type' => 'video/mp4'],
            ['title' => 'Script', 'url' => 'javascript:alert(1)'],
            ['title' => 'Fine'],
        ], ['image' => 'file:///etc/passwd']));

        $this->assertSame(['Fine'], array_column($parsed['episodes'], 'title'));
        $this->assertNull($parsed['image_url']);
    }

    public function test_a_missing_guid_falls_back_to_the_enclosure_url(): void
    {
        $xml = '<rss version="2.0"><channel><title>S</title><item><title>E</title><enclosure url="https://cdn.test/a.mp3" type="audio/mpeg"/></item></channel></rss>';

        $this->assertSame('https://cdn.test/a.mp3', FeedParser::parse($xml)['episodes'][0]['guid']);
    }

    public function test_something_that_is_not_a_feed_is_refused(): void
    {
        foreach (['<html><body>no</body></html>', 'not xml at all', '<rss version="2.0"><channel><description>x</description></channel></rss>'] as $bad) {
            try {
                FeedParser::parse($bad);
                $this->fail('accepted: '.$bad);
            } catch (InvalidFeedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_durations(): void
    {
        $this->assertSame(3600, FeedParser::duration('3600'));
        $this->assertSame(65, FeedParser::duration('1:05'));
        $this->assertNull(FeedParser::duration(''));
        $this->assertNull(FeedParser::duration('about an hour'));
        $this->assertNull(FeedParser::duration('0'));
    }
}
