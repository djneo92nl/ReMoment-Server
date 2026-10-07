<?php

namespace Tests\Unit;

use App\Domain\Device\State;
use App\Domain\Media\AlbumData;
use App\Domain\Media\ArtistData;
use App\Domain\Media\NowPlaying;
use App\Domain\Media\NowPlayingLabel;
use App\Domain\Media\Radio;
use App\Domain\Media\Source;
use App\Domain\Media\TrackData;
use PHPUnit\Framework\TestCase;

class NowPlayingLabelTest extends TestCase
{
    public function test_a_track_shows_its_name_with_artist_and_album(): void
    {
        $np = new NowPlaying(
            track: new TrackData(id: '1', name: 'Song', artist: new ArtistData(name: 'Band')),
            album: new AlbumData(name: 'LP', artist: new ArtistData(name: 'Band')),
            state: 'playing', position: 0, type: 'music',
        );

        $this->assertSame(['title' => 'Song', 'subtitle' => 'Band · LP'], NowPlayingLabel::for($np, State::Playing));
    }

    public function test_a_radio_station_without_a_track_is_labelled_radio(): void
    {
        $np = new NowPlaying(radio: new Radio(name: 'KINK'), state: 'playing', position: 0, type: 'radio');

        $this->assertSame(['title' => 'KINK', 'subtitle' => 'Radio'], NowPlayingLabel::for($np, State::Playing));
    }

    public function test_a_source_shows_its_name_and_type(): void
    {
        $np = new NowPlaying(source: new Source(name: 'Line In', sourceType: 'LINE'), state: 'playing', position: 0, type: 'music');

        $this->assertSame(['title' => 'Line In', 'subtitle' => 'LINE'], NowPlayingLabel::for($np, State::Playing));
    }

    public function test_without_anything_playing_the_state_gives_the_title(): void
    {
        $this->assertSame('Standby', NowPlayingLabel::for(null, State::Standby)['title']);
        $this->assertSame('Unreachable', NowPlayingLabel::for(null, State::Unreachable)['title']);
        $this->assertSame('No content', NowPlayingLabel::for(null, State::Paused, 'No content')['title']);
        $this->assertSame('', NowPlayingLabel::for(null, State::Standby)['subtitle']);
    }
}
