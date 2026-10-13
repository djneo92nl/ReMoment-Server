<?php

namespace Tests\Feature;

use App\Domain\Device\Capabilities;
use App\Domain\Device\DiscoveredDevicePersister;
use App\Domain\Device\MultiRoomPeers;
use App\Events\Device\NowPlayingEnded;
use App\Events\Device\NowPlayingUpdated;
use App\Integrations\BangOlufsen\Ase\ConverterDiscovery;
use App\Integrations\BangOlufsen\Ase\ConverterDriver;
use App\Integrations\BangOlufsen\Ase\MusicPlayerDriver;
use App\Integrations\BangOlufsen\Ase\Services\ConverterListener;
use App\Integrations\Common\HttpConnector;
use App\Integrations\Common\UnsupportedOperationException;
use App\Models\Device;
use App\Models\DeviceMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use Tests\Support\FakeAseConnector;
use Tests\TestCase;

/** A BeoLink Converter NL/ML as a device. Payload shapes are what a converter (fw 1.4.3) answered and pushed. */
class ConverterIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const RADIO = 'RADIO:1790.1179011.24978398@products.bang-olufsen.com';

    private function beoDevice(string $type = 'BEOLINK_CONVERTER_NL_ML'): array
    {
        return ['beoDevice' => [
            'productId' => ['productType' => $type, 'typeNumber' => '1790', 'serialNumber' => '24978398', 'itemNumber' => '1179011'],
            'productFriendlyName' => ['productFriendlyName' => 'BeoLink Converter NL/ML'],
            'software' => ['version' => '1.4.3.19864'],
            'hardware' => ['mac' => '00:09:A7:05:30:7E'],
        ]];
    }

    private function api(): FakeAseConnector
    {
        $api = new FakeAseConnector;
        $this->app->bind(HttpConnector::class, fn () => $api);

        return $api;
    }

    public function test_the_model_has_a_driver_and_only_the_read_capabilities(): void
    {
        $this->assertSame(
            ['source_control', 'device_info'],
            Capabilities::forDriver(ConverterDriver::class),
        );
        $this->assertSame(ConverterListener::class, config('devices.listeners')[ConverterDriver::class]);
        $this->assertContains(ConverterDiscovery::class, config('devices.discoverers'));
    }

    public function test_a_given_host_is_discovered_and_stored(): void
    {
        $this->api()->seed('BeoDevice', $this->beoDevice());

        $found = (new ConverterDiscovery)->withHosts(['192.168.1.10'])->discover();

        $this->assertCount(1, $found);
        $this->assertSame('192.168.1.10', $found[0]->ip_address);
        $this->assertSame('BeoLink Converter NL/ML', $found[0]->device_product_type);
        $this->assertSame(ConverterDriver::class, $found[0]->device_driver);
        $this->assertSame('1790.1179011.24978398@products.bang-olufsen.com', $found[0]->meta['converter_jid']);

        $device = (new DiscoveredDevicePersister)->persist($found[0]);

        $this->assertSame('Bang & Olufsen', $device->device_brand_name);
        $this->assertSame('ASE', $device->device_driver_name);
        $this->assertSame('1790.1179011.24978398@products.bang-olufsen.com', DeviceMeta::where('device_id', $device->id)->where('key', 'converter_jid')->value('value'));
        $this->assertNull(DeviceMeta::where('device_id', $device->id)->where('key', 'ase_jid')->first());

        // Found again, it is the same device.
        (new DiscoveredDevicePersister)->persist((new ConverterDiscovery)->withHosts(['192.168.1.10'])->discover()[0]);
        $this->assertSame(1, Device::count());
    }

    public function test_hosts_that_are_no_converter_or_do_not_answer_are_explained(): void
    {
        $api = $this->api()->seed('BeoDevice', $this->beoDevice('BEOSOUND_ESSENCE_MK2'));

        $discovery = (new ConverterDiscovery)->withHosts(['192.168.1.25']);
        $this->assertSame([], $discovery->discover());
        $this->assertStringContainsString('not a BeoLink Converter', $discovery->diagnostics()[0]);

        $this->app->bind(HttpConnector::class, fn () => new FakeAseConnector); // answers 404
        $this->assertSame([], $discovery->withHosts(['192.168.1.99'])->discover());
        $this->assertStringContainsString('did not answer', $discovery->diagnostics()[0]);

        $none = new ConverterDiscovery;
        $this->assertSame([], $none->discover());
        $this->assertStringContainsString('--host', $none->diagnostics()[0]);

        $this->assertSame([], $api->bodies);
    }

    public function test_a_stored_converter_is_probed_again_without_a_host(): void
    {
        Device::factory()->create(['ip_address' => '192.168.1.10', 'device_product_type' => 'BeoLink Converter NL/ML']);
        $this->api()->seed('BeoDevice', $this->beoDevice());

        $this->assertCount(1, (new ConverterDiscovery)->discover());
    }

    public function test_the_driver_reads_device_info_and_sources_but_writes_nothing(): void
    {
        $api = (new FakeAseConnector)
            ->seed('BeoDevice', $this->beoDevice())
            ->seed('BeoZone/Zone/Sources', ['sources' => [
                [self::RADIO, ['friendlyName' => 'RADIO', 'id' => self::RADIO, 'sourceType' => ['type' => 'RADIO'], 'category' => 'RADIO', 'inUse' => true, 'product' => ['jid' => '1790.1179011.24978398@products.bang-olufsen.com']]],
                ['CD:1790.1179011.24978398@products.bang-olufsen.com', ['friendlyName' => 'CD', 'sourceType' => ['type' => 'CD'], 'category' => 'MUSIC', 'inUse' => true]],
            ]]);

        $driver = new ConverterDriver(Device::factory()->make(['ip_address' => '192.168.1.10']));
        $driver->deviceApi = $api;

        $info = $driver->getDeviceInfo();
        $this->assertSame('BeoLink Converter NL/ML', $info->name);
        $this->assertSame('BEOLINK_CONVERTER_NL_ML', $info->productType);
        $this->assertSame('1.4.3.19864', $info->firmware);
        $this->assertFalse($info->renamable);

        $sources = $driver->getSources();
        $this->assertSame(['RADIO', 'CD'], array_map(fn ($s) => $s->friendlyName, $sources));
        $this->assertSame('RADIO', $sources[0]->sourceType);

        $this->expectException(UnsupportedOperationException::class);
        try {
            $driver->setDeviceName('Other');
        } finally {
            $this->assertSame(['GET BeoDevice', 'GET BeoZone/Zone/Sources', 'GET BeoZone/Zone/ActiveSources'], $api->requests);
        }
    }

    public function test_a_player_joins_the_converter_by_the_converter_adding_it_as_listener(): void
    {
        $api = $this->api();
        $converter = Device::factory()->create(['ip_address' => '192.168.1.10', 'device_name' => 'Converter', 'device_driver' => ConverterDriver::class, 'device_product_type' => 'BeoLink Converter NL/ML']);
        DeviceMeta::create(['device_id' => $converter->id, 'key' => 'converter_jid', 'value' => '1790.1179011.24978398@products.bang-olufsen.com']);
        $moment = Device::factory()->create(['ip_address' => '192.168.1.29', 'device_name' => 'Moment', 'device_driver' => MusicPlayerDriver::class]);
        DeviceMeta::create(['device_id' => $moment->id, 'key' => 'ase_jid', 'value' => '2997.1290084.26093928@products.bang-olufsen.com']);

        // the converter's jid in the Moment's joinable peers resolves to the converter device
        $this->assertSame([$converter->id], MultiRoomPeers::devices(['1790.1179011.24978398@products.bang-olufsen.com'], $moment)->pluck('id')->all());

        $moment->driver->joinSession($converter);

        $this->assertSame(['POST BeoZone/Zone/ActiveSources/primaryExperience'], $api->requests);
        $this->assertSame([['listener' => ['jid' => '2997.1290084.26093928@products.bang-olufsen.com']]], $api->bodies);
    }

    private function feed(ConverterListener $listener, array $notification): void
    {
        $method = (new ReflectionClass(ConverterListener::class))->getMethod('applyNotification');
        $method->setAccessible(true);
        $method->invoke($listener, ['notification' => $notification], '7');
    }

    private const CONVERTER = '1790.1179011.24978398@products.bang-olufsen.com';

    private const MOMENT = '2997.1290084.26093928@products.bang-olufsen.com';

    private function experience(string $id, string $name, string $type, ?array $listener, string $product = self::CONVERTER): array
    {
        return array_filter([
            'source' => [
                'id' => $id, 'friendlyName' => $name, 'sourceType' => ['type' => $type], 'category' => 'RADIO',
                'product' => ['jid' => $product],
            ],
            'listener' => $listener,
            'state' => 'play',
        ], fn ($value) => $value !== null);
    }

    private function sourceNotification(string $id = self::RADIO, string $name = 'RADIO', string $type = 'RADIO', ?array $listener = null, string $product = self::CONVERTER): array
    {
        return ['type' => 'SOURCE', 'kind' => 'source', 'data' => [
            'primary' => $id,
            'primaryExperience' => $this->experience($id, $name, $type, $listener, $product),
        ]];
    }

    private function experienceChanged(?array $listener, string $id = self::RADIO): array
    {
        return ['type' => 'SOURCE_EXPERIENCE_CHANGED', 'kind' => 'source', 'data' => [
            'primaryExperience' => $this->experience($id, 'RADIO', 'RADIO', $listener),
        ]];
    }

    private function legacy(int $n): array
    {
        return ['id' => 844, 'type' => 'NOW_PLAYING_LEGACY', 'kind' => 'playing', 'data' => ['trackNumber' => $n]];
    }

    public function test_the_tuner_preset_is_shown_once_per_change(): void
    {
        Event::fake();
        $listener = new ConverterListener('http://example.test');

        $this->feed($listener, $this->sourceNotification());
        foreach ([2, 2, 2, 2] as $n) { // the converter repeats it about once a second
            $this->feed($listener, $this->legacy($n));
        }
        $this->feed($listener, $this->legacy(3));

        Event::assertDispatched(NowPlayingUpdated::class, fn (NowPlayingUpdated $e) => $e->nowPlaying->track?->name === 'Preset 2'
            && $e->nowPlaying->source?->name === 'RADIO'
            && $e->nowPlaying->track->meta === ['trackNumber' => 2]
            && $e->nowPlaying->track->artist === null);
        Event::assertDispatched(NowPlayingUpdated::class, fn (NowPlayingUpdated $e) => $e->nowPlaying->track?->name === 'Preset 3');
        // the SOURCE itself, "Preset 2" once, "Preset 3" once
        Event::assertDispatchedTimes(NowPlayingUpdated::class, 3);
    }

    public function test_a_cd_reports_tracks_and_a_new_source_forgets_the_old_number(): void
    {
        Event::fake();
        $listener = new ConverterListener('http://example.test');

        $this->feed($listener, $this->sourceNotification());
        $this->feed($listener, $this->legacy(5));
        $this->feed($listener, $this->sourceNotification('CD:1790.1179011.24978398@products.bang-olufsen.com', 'CD', 'CD'));
        $this->feed($listener, $this->legacy(5)); // same number on another source is a change

        Event::assertDispatched(NowPlayingUpdated::class, fn (NowPlayingUpdated $e) => $e->nowPlaying->track?->name === 'Preset 5');
        Event::assertDispatched(NowPlayingUpdated::class, fn (NowPlayingUpdated $e) => $e->nowPlaying->track?->name === 'Track 5' && $e->nowPlaying->source?->name === 'CD');
    }

    public function test_plays_locally_follows_the_converters_own_jid_in_the_listener_list(): void
    {
        Event::fake();
        $listener = new ConverterListener('http://example.test', self::CONVERTER);

        $this->feed($listener, $this->sourceNotification(listener: [self::CONVERTER]));
        $this->feed($listener, $this->sourceNotification(listener: [self::MOMENT]));
        $this->feed($listener, $this->sourceNotification(listener: null));
        // a Network Link stream (the M5's B&O Radio) on the master's speakers: its product is not the converter
        $this->feed($listener, $this->sourceNotification('beoradio:2714.1200298.26924927@products.bang-olufsen.com', 'B&O Radio', 'UNDEFINED', [self::CONVERTER, self::MOMENT], '2714.1200298.26924927@products.bang-olufsen.com'));

        $flags = collect(Event::dispatched(NowPlayingUpdated::class))
            ->map(fn (array $args) => $args[0]->nowPlaying->source?->playsLocally)
            ->all();

        $this->assertSame([true, false, null, true], $flags);
    }

    public function test_a_joining_or_leaving_player_announces_the_source_again_with_the_preset(): void
    {
        Event::fake();
        $listener = new ConverterListener('http://example.test', self::CONVERTER);

        $this->feed($listener, $this->sourceNotification(listener: [self::CONVERTER]));
        $this->feed($listener, $this->legacy(2));
        $this->feed($listener, $this->experienceChanged([self::CONVERTER, self::MOMENT])); // the Moment joins: still local
        $this->feed($listener, $this->experienceChanged([self::MOMENT])); // the Ouverture goes to standby
        $this->feed($listener, $this->experienceChanged([self::MOMENT])); // repeated: nothing new

        $events = collect(Event::dispatched(NowPlayingUpdated::class))->map(fn (array $args) => $args[0]->nowPlaying);

        // SOURCE, Preset 2, then only the change of the flag
        $this->assertCount(3, $events);
        $this->assertSame('Preset 2', $events[2]->track?->name);
        $this->assertFalse($events[2]->source->playsLocally);
        $this->assertSame(self::RADIO, $events[2]->source->jid);
    }

    public function test_a_changed_listener_list_without_a_preset_announces_the_bare_source(): void
    {
        Event::fake();
        $listener = new ConverterListener('http://example.test', self::CONVERTER);

        $this->feed($listener, $this->sourceNotification(listener: [self::CONVERTER]));
        $this->feed($listener, $this->experienceChanged([self::MOMENT]));

        $last = collect(Event::dispatched(NowPlayingUpdated::class))->last()[0]->nowPlaying;

        $this->assertNull($last->track);
        $this->assertFalse($last->source->playsLocally);
        $this->assertFalse($last->source->toArray()['playsLocally']);
    }

    public function test_other_notifications_still_use_the_ase_handling(): void
    {
        Event::fake();
        $listener = new ConverterListener('http://example.test');

        $this->feed($listener, ['type' => 'SOURCE_EXPERIENCE_CHANGED', 'data' => ['primaryExperience' => []]]); // no source: nothing to announce
        $this->feed($listener, $this->legacy(0));
        Event::assertNothingDispatched();

        $this->feed($listener, ['type' => 'NOW_PLAYING_NET_RADIO', 'data' => [
            'name' => 'NPO Radio 2', 'image' => [], 'liveDescription' => 'Racoon - Dicht Bij Het Vuur',
        ]]);
        Event::assertDispatched(NowPlayingUpdated::class, fn (NowPlayingUpdated $e) => $e->nowPlaying->radio?->name === 'NPO Radio 2' && $e->nowPlaying->track?->artist?->name === 'Racoon');

        $this->feed($listener, ['type' => 'SOURCE', 'data' => []]);
        Event::assertDispatched(NowPlayingEnded::class);
    }
}
