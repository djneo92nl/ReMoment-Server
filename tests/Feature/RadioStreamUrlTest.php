<?php

namespace Tests\Feature;

use App\Integrations\BangOlufsen\Ase\MusicPlayerDriver as AseDriver;
use App\Integrations\Sonos\MusicPlayerDriver as SonosDriver;
use App\Models\Device;
use App\Models\RadioStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A station's native identifier (BeoRadio, TuneIn) wins over its plain stream URL; the URL is the fallback. */
class RadioStreamUrlTest extends TestCase
{
    use RefreshDatabase;

    private function station(array $meta): RadioStation
    {
        $station = RadioStation::create(['name' => 'Radio Test']);

        foreach ($meta as $key => $value) {
            $station->setMeta($key, $value);
        }

        return $station->load('meta');
    }

    /** A Sonos driver that records the stream URI it would hand to the speaker. */
    private function sonos(): SonosDriver
    {
        return new class(Device::factory()->make()) extends SonosDriver
        {
            public array $streams = [];

            protected function useSonosStream(string $uri, string $title): void
            {
                $this->streams[] = $uri;
            }
        };
    }

    public function test_sonos_prefers_tunein_over_the_stream_url(): void
    {
        $sonos = $this->sonos();
        $sonos->playRadioStation($this->station(['tunein' => 's15200', 'url' => 'http://stream.test/radio.mp3']));

        $this->assertCount(1, $sonos->streams);
        $this->assertStringStartsWith('x-sonosapi-stream:s15200', $sonos->streams[0]);
    }

    public function test_sonos_falls_back_to_the_stream_url_without_a_tunein_id(): void
    {
        $sonos = $this->sonos();
        $station = $this->station(['url' => 'http://stream.test/radio.mp3']);

        $this->assertTrue($sonos->canPlayRadioStation($station));
        $sonos->playRadioStation($station);

        $this->assertSame(['x-rincon-mp3radio://stream.test/radio.mp3'], $sonos->streams);
    }

    public function test_sonos_cannot_play_a_station_with_neither(): void
    {
        $this->assertFalse($this->sonos()->canPlayRadioStation($this->station([])));
    }

    public function test_ase_prefers_beoradio_over_the_stream_url_and_falls_back_to_it(): void
    {
        $ase = new class(Device::factory()->make()) extends AseDriver
        {
            public array $played = [];

            public function playBeoRadioStation(string $id): void
            {
                $this->played[] = ['beoradio', $id];
            }

            public function playStreamUrl(string $url, string $title, ?string $art = null): void
            {
                $this->played[] = ['url', $url];
            }
        };

        $ase->playRadioStation($this->station(['beoradio' => '6336061628069729', 'url' => 'http://stream.test/radio.mp3']));
        $ase->playRadioStation($this->station(['url' => 'http://stream.test/radio.mp3']));

        $this->assertSame([['beoradio', '6336061628069729'], ['url', 'http://stream.test/radio.mp3']], $ase->played);
        $this->assertFalse($ase->canPlayRadioStation($this->station([])));
    }
}
