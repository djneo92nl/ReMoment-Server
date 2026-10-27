<?php

namespace Tests\Unit;

use App\Integrations\Jellyfin\JellyfinNowPlaying;
use PHPUnit\Framework\TestCase;

class JellyfinNowPlayingTest extends TestCase
{
    public function test_an_episode_on_the_tv_with_its_series_poster_and_position(): void
    {
        $sessions = [
            ['RemoteEndPoint' => '192.168.1.50', 'NowPlayingItem' => ['Id' => 'x', 'Name' => 'Other']],
            ['RemoteEndPoint' => '::ffff:192.168.1.105', 'Client' => 'Jellyfin Android TV', 'NowPlayingItem' => [
                'Id' => 'ep1', 'Name' => 'Pilot', 'Type' => 'Episode', 'SeriesName' => 'The Show', 'SeriesId' => 'ser1',
                'ParentIndexNumber' => 1, 'IndexNumber' => 2, 'RunTimeTicks' => 30_000_000_000,
            ], 'PlayState' => ['PositionTicks' => 6_000_000_000, 'IsPaused' => true]],
        ];

        $playing = JellyfinNowPlaying::forClient($sessions, '192.168.1.105', 'http://jf:8096');

        $this->assertSame('S01E02 · Pilot', $playing->track->name);
        $this->assertSame('The Show', $playing->track->artist->name);
        $this->assertSame(3000, $playing->track->duration);
        $this->assertSame(['paused', 600], [$playing->state, $playing->position]);
        $this->assertSame(['http://jf:8096/Items/ser1/Images/Primary?maxWidth=600'], $playing->track->images);
        $this->assertSame('Jellyfin', $playing->source->name);
    }

    public function test_no_session_of_that_client_or_nothing_playing_is_null(): void
    {
        $this->assertNull(JellyfinNowPlaying::forClient([['RemoteEndPoint' => '192.168.1.105']], '192.168.1.105', 'http://jf'));
        $this->assertNull(JellyfinNowPlaying::forClient([], '192.168.1.105', 'http://jf'));
    }
}
