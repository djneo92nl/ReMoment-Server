<?php

namespace Tests\Unit\Sonos;

use App\Integrations\Sonos\Connectors\SourceControls;
use App\Integrations\Sonos\SonosInputs;
use App\Models\Device;
use Tests\TestCase;

class SourceControlsTest extends TestCase
{
    /** Stands in for duncan3dc's Controller: records SOAP calls, answers GetMediaInfo. */
    private function speaker(string $ip = '10.0.0.5', string $uri = 'x-rincon-queue:RINCON_1#0'): object
    {
        return new class($ip, $uri)
        {
            /** @var array<int, array> */
            public array $calls = [];

            public bool $played = false;

            public function __construct(private string $ip, private string $uri) {}

            public function getIp(): string
            {
                return $this->ip;
            }

            public function play(): void
            {
                $this->played = true;
            }

            public function soap(string $service, string $action, array $params = []): object
            {
                $this->calls[] = [$service, $action, $params];
                $uri = $this->uri;

                return new class($uri)
                {
                    public function __construct(private string $uri) {}

                    public function getArray(): array
                    {
                        return ['CurrentURI' => $this->uri];
                    }
                };
            }
        };
    }

    private function driver(object $speaker, string $model = 'Sonos Ray'): object
    {
        $device = new Device(['device_name' => 'TV', 'device_product_type' => $model, 'ip_address' => '10.0.0.5']);

        return new class($speaker, $device)
        {
            use SourceControls;

            public bool $left = false;

            private ?object $deviceApi = null;

            public function __construct(private object $speaker, public Device $device) {}

            public function deviceApiClient(): object
            {
                return $this->speaker;
            }

            public function getMultiRoomId(): string
            {
                return 'RINCON_ABC';
            }

            public function leaveSession(): void
            {
                $this->left = true;
            }
        };
    }

    public function test_a_soundbar_offers_the_tv_input_and_marks_it_in_use(): void
    {
        $idle = $this->driver($this->speaker())->getSources();
        $onTv = $this->driver($this->speaker(uri: 'x-sonos-htastream:RINCON_ABC:spdif'))->getSources();

        $this->assertSame(['tv'], array_map(fn ($s) => $s->sourceId, $idle));
        $this->assertFalse($idle[0]->inUse);
        $this->assertTrue($onTv[0]->inUse);
    }

    public function test_a_speaker_without_inputs_has_no_sources(): void
    {
        $this->assertSame([], $this->driver($this->speaker(), 'Sonos Era 100')->getSources());
    }

    public function test_activating_tv_points_the_transport_at_the_speakers_own_stream_and_plays(): void
    {
        $speaker = $this->speaker();

        $this->driver($speaker)->activateSource('tv');

        $this->assertSame(
            ['AVTransport', 'SetAVTransportURI', ['CurrentURI' => 'x-sonos-htastream:RINCON_ABC:spdif', 'CurrentURIMetaData' => '']],
            $speaker->calls[0]
        );
        $this->assertTrue($speaker->played);
    }

    public function test_an_input_the_model_lacks_is_refused(): void
    {
        $speaker = $this->speaker();

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->driver($speaker)->activateSource('line_in');
        } finally {
            $this->assertSame([], $speaker->calls);
        }
    }

    public function test_a_grouped_follower_leaves_its_group_first(): void
    {
        $driver = $this->driver($this->speaker(ip: '10.0.0.99'));

        $driver->activateSource('tv');

        $this->assertTrue($driver->left);
    }

    public function test_inputs_by_model(): void
    {
        $this->assertSame(['tv'], SonosInputs::forModel('Sonos Ray'));
        $this->assertSame(['tv'], SonosInputs::forModel('Sonos Arc Ultra'));
        $this->assertSame(['tv'], SonosInputs::forModel('Sonos Beam (Gen 2)'));
        $this->assertSame(['line_in'], SonosInputs::forModel('Sonos Port'));
        $this->assertSame(['tv', 'line_in'], SonosInputs::forModel('Sonos Amp'));
        $this->assertSame([], SonosInputs::forModel('Sonos One'));
        $this->assertSame([], SonosInputs::forModel(null));
    }
}
