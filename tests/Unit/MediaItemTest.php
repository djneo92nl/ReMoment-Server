<?php

namespace Tests\Unit;

use App\Domain\Media\MediaItem;
use App\Integrations\Jellyfin\JellyfinItems;
use PHPUnit\Framework\TestCase;

/** The normalised media item and Jellyfin's mapping onto it. */
class MediaItemTest extends TestCase
{
    public function test_the_id_is_namespaced_by_the_server_and_parsed_back(): void
    {
        $item = new MediaItem(serverId: 3, itemId: 'abc', kind: 'movie', title: 'A Film');

        $this->assertSame('3:abc', $item->id());
        $this->assertSame([3, 'abc'], MediaItem::parse('3:abc'));
        $this->assertSame([null, 'abc'], MediaItem::parse('abc'));
        $this->assertSame([12, 'a:b'], MediaItem::parse('12:a:b'));
        $this->assertSame(['id' => '3:abc', 'server_id' => 3, 'item_id' => 'abc', 'kind' => 'movie', 'title' => 'A Film', 'subtitle' => null, 'year' => null, 'image' => null, 'progress' => 0, 'resume_position' => 0.0, 'duration' => null, 'library' => null], $item->toArray());
    }

    public function test_a_jellyfin_movie_is_normalised(): void
    {
        $item = JellyfinItems::item([
            'Id' => 'm1', 'Name' => 'A Film', 'Type' => 'Movie', 'ProductionYear' => 2020,
            'RunTimeTicks' => 72_000_000_000, 'UserData' => ['PlaybackPositionTicks' => 18_000_000_000], 'ImageTags' => ['Primary' => 'x'],
        ], 7, 'http://jf:8096');

        $this->assertSame(['7:m1', 'movie', 'A Film', '2020', 2020], [$item->id(), $item->kind, $item->title, $item->subtitle, $item->year]);
        $this->assertSame([25, 1800.0, 7200], [$item->progress, $item->resumePosition, $item->duration]);
        $this->assertSame('http://jf:8096/Items/m1/Images/Primary?maxWidth=400', $item->image);
    }

    public function test_an_episode_is_titled_by_its_series_and_falls_back_to_the_series_poster(): void
    {
        $item = JellyfinItems::item(['Id' => 'e1', 'Name' => 'Pilot', 'Type' => 'Episode', 'SeriesName' => 'The Show', 'SeriesId' => 'ser1', 'ParentIndexNumber' => 2, 'IndexNumber' => 3], 1, 'http://jf');

        $this->assertSame(['episode', 'The Show', 'S2E3 · Pilot', 0, 0.0], [$item->kind, $item->title, $item->subtitle, $item->progress, $item->resumePosition]);
        $this->assertSame('http://jf/Items/ser1/Images/Primary?maxWidth=400', $item->image);
    }

    public function test_items_without_an_id_are_dropped_and_ticks_round_trip(): void
    {
        $this->assertCount(1, JellyfinItems::items([['Name' => 'No id'], ['Id' => 'a', 'Name' => 'A']], 1, 'http://jf'));
        $this->assertSame(250, JellyfinItems::ticks(250 / JellyfinItems::TICKS_PER_SECOND));
        $this->assertSame(6_000_000_000, JellyfinItems::ticks(600));
    }
}
