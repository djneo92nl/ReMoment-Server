<?php

namespace Tests\Unit;

use App\Integrations\Cast\CastNowPlaying;
use PHPUnit\Framework\TestCase;

class CastNowPlayingTest extends TestCase
{
    private const APP = ['appId' => 'AndroidNativeApp', 'displayName' => 'Jellyfin', 'transportId' => 'abc'];

    public function test_an_app_without_a_media_session_is_just_the_app(): void
    {
        $playing = CastNowPlaying::fromStatus(['applications' => [self::APP]], []);

        $this->assertNull($playing->track);
        $this->assertSame('Jellyfin', $playing->source->name);
        $this->assertSame('playing', $playing->state);
    }

    public function test_a_casted_session_gives_title_artwork_state_and_position(): void
    {
        $media = [['playerState' => 'PAUSED', 'currentTime' => 61.4, 'media' => [
            'contentId' => 'x', 'duration' => 3600.2, 'streamType' => 'BUFFERED',
            'metadata' => ['title' => 'Episode 1', 'seriesTitle' => 'The Show', 'images' => [['url' => 'https://img/1.jpg']]],
        ]]];

        $playing = CastNowPlaying::fromStatus(['applications' => [self::APP]], $media);

        $this->assertSame('Episode 1', $playing->track->name);
        $this->assertSame('The Show', $playing->track->artist->name);
        $this->assertSame(3600, $playing->track->duration);
        $this->assertSame(['https://img/1.jpg'], $playing->track->images);
        $this->assertSame(['paused', 61], [$playing->state, $playing->position]);
    }

    public function test_no_app_or_the_idle_screen_is_nothing_playing(): void
    {
        $this->assertNull(CastNowPlaying::fromStatus([], []));
        $this->assertNull(CastNowPlaying::fromStatus(['applications' => [['appId' => 'E8C28D3C', 'displayName' => 'Backdrop', 'isIdleScreen' => true]]], []));
    }
}
