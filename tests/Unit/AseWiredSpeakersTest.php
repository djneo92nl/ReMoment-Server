<?php

namespace Tests\Unit;

use App\Integrations\BangOlufsen\Ase\Connectors\WiredSpeakerControls;
use App\Integrations\Common\HttpConnector;
use Tests\Support\FakeAseConnector;
use Tests\TestCase;

/** Shapes below are what a Moment (types listed) and an Essence (no types listed) answer. */
class AseWiredSpeakersTest extends TestCase
{
    private const PATH = 'BeoZone/Zone/Sound/SpeakerWiredSetup';

    private function driver(FakeAseConnector $api): object
    {
        return new class($api)
        {
            use WiredSpeakerControls;

            public function __construct(private HttpConnector $api) {}

            protected function deviceApiClient(): HttpConnector
            {
                return $this->api;
            }
        };
    }

    private function outputEntry(string $id, string $allocation, bool $sense, string $type, array $types, string $sound = 'none'): array
    {
        return [
            'id' => $id, 'connector' => ['type' => 'external', 'allocation' => $allocation, 'number' => 1], 'sense' => $sense, 'type' => $type, 'sound' => $sound,
            '_capabilities' => ['editable' => ['type', 'sound'], 'value' => ['sound' => ['none', 'localizationNoise'], 'type' => $types]],
            '_links' => ['/relation/modify' => ['href' => "./{$id}"]],
        ];
    }

    private function moment(string $type1 = 'Other', string $sound1 = 'none'): array
    {
        $types = ['None', 'Beolab 1', 'Beolab 3', 'Other', 'Line'];

        return ['speakerWiredSetup' => ['speaker' => [
            $this->outputEntry('pl_1', 'left', true, $type1, $types, $sound1),
            $this->outputEntry('pl_2', 'right', true, 'Other', $types),
        ]]];
    }

    private function essence(): array
    {
        return ['speakerWiredSetup' => ['speaker' => [
            $this->outputEntry('pl_1', 'left', false, 'Other', []),
            $this->outputEntry('pl_2', 'right', false, 'Other', []),
        ]]];
    }

    public function test_reads_the_outputs_of_a_moment(): void
    {
        $result = $this->driver((new FakeAseConnector)->seed(self::PATH, $this->moment()))->getWiredSpeakers()->toArray();

        $this->assertCount(2, $result['speakers']);
        $this->assertSame(['id' => 'pl_1', 'position' => 'left', 'connected' => true, 'type' => 'Other', 'types' => ['None', 'Beolab 1', 'Beolab 3', 'Other', 'Line'], 'sound' => 'none', 'sounds' => ['none', 'localizationNoise']], $result['speakers'][0]);
        $this->assertSame('right', $result['speakers'][1]['position']);
    }

    public function test_an_essence_that_lists_no_types_gets_the_known_list_instead(): void
    {
        $speaker = $this->driver((new FakeAseConnector)->seed(self::PATH, $this->essence()))->getWiredSpeakers()->toArray()['speakers'][0];

        $this->assertFalse($speaker['connected']);
        $this->assertCount(34, $speaker['types']);
        $this->assertContains('Beolab 20', $speaker['types']);
        $this->assertContains('Line', $speaker['types']);
        $this->assertSame('Other', $speaker['type']);
    }

    public function test_a_failing_read_is_an_error_not_an_empty_list(): void
    {
        $api = (new FakeAseConnector)->seed(self::PATH, ['error' => ['message' => 'busy']], 500);

        $this->expectExceptionMessage('HTTP 500');

        $this->driver($api)->getWiredSpeakers();
    }

    public function test_sets_the_speaker_type_with_the_body_the_device_accepts_and_checks_it_took(): void
    {
        $api = (new FakeAseConnector)->seed(self::PATH, $this->moment())
            ->onPut(self::PATH.'/pl_1', fn ($body, $a) => $a->replace(self::PATH, $this->moment($body['speaker']['type'])));

        $this->driver($api)->setWiredSpeaker('pl_1', type: 'Beolab 3');

        $this->assertSame([['speaker' => ['type' => 'Beolab 3']]], $api->bodies);
        $this->assertContains('PUT '.self::PATH.'/pl_1', $api->requests);
    }

    public function test_stops_a_test_noise(): void
    {
        $api = (new FakeAseConnector)->seed(self::PATH, $this->moment(sound1: 'localizationNoise'))
            ->onPut(self::PATH.'/pl_1', fn ($body, $a) => $a->replace(self::PATH, $this->moment('Other', $body['speaker']['sound'])));

        $this->driver($api)->setWiredSpeaker('pl_1', sound: 'none');

        $this->assertSame([['speaker' => ['sound' => 'none']]], $api->bodies);
    }

    public function test_an_unknown_output_type_or_sound_is_refused_before_anything_is_sent(): void
    {
        $api = (new FakeAseConnector)->seed(self::PATH, $this->moment());
        $driver = $this->driver($api);

        foreach ([['pl_9', 'Line', null], ['pl_1', 'Beolab 99', null], ['pl_1', null, 'screech']] as [$id, $type, $sound]) {
            try {
                $driver->setWiredSpeaker($id, $type, $sound);
                $this->fail('expected an InvalidArgumentException');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([], $api->bodies);
    }

    public function test_nothing_to_change_sends_nothing(): void
    {
        $api = (new FakeAseConnector)->seed(self::PATH, $this->moment());

        $this->driver($api)->setWiredSpeaker('pl_1');

        $this->assertSame([], $api->bodies);
    }

    public function test_a_change_the_device_ignores_is_reported(): void
    {
        $api = (new FakeAseConnector)->seed(self::PATH, $this->moment());
        $api->ignoreWrites = true;

        $this->expectExceptionMessage('did not apply the speaker change');

        $this->driver($api)->setWiredSpeaker('pl_1', type: 'Line');
    }
}
