<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Domain\Tags\PlayTags;
use App\Livewire\PlayTagManager;
use App\Models\AdminToken;
use App\Models\Client;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\PhysicalItem;
use App\Models\Media\Track;
use App\Models\PlayTag;
use App\Models\RadioStation;
use App\Models\User;
use App\Support\SelectedDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeMovableDriver;
use Tests\Support\FakePlayerDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

class PlayTagTest extends TestCase
{
    use MakesLibraryTracks, RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        $this->device = Device::factory()->driver(FakeMovableDriver::class)->inState(State::Playing)->create(['device_name' => 'Kitchen']);
    }

    private function libraryTrack(): Track
    {
        $artist = Artist::create(['name' => 'Portishead']);
        $album = Album::create(['name' => 'Dummy '.uniqid(), 'artist_id' => $artist->id]);

        return $this->makeLibraryTrack($album, 'Sour Times');
    }

    private function albumTag(array $attributes = []): PlayTag
    {
        $track = $this->libraryTrack();

        return PlayTag::create($attributes + ['uid' => '04A31F92', 'target_type' => 'album', 'target_id' => $track->album_id]);
    }

    public function test_a_uid_is_compared_without_separators_and_case(): void
    {
        $this->assertSame('04A31F92', PlayTags::normalise('04:a3:1f:92'));
        $tag = $this->albumTag();

        $this->getJson('/api/tags/04:a3:1f:92')->assertOk()->assertJsonPath('data.id', $tag->id)->assertJsonPath('data.target.type', 'album');
    }

    public function test_tapping_a_tag_plays_its_album_on_the_given_device(): void
    {
        $tag = $this->albumTag();

        $this->postJson('/api/tags/04a31f92/play', ['device_id' => $this->device->id])
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('device.name', 'Kitchen')->assertJsonPath('tag.id', $tag->id);

        $this->assertNotEmpty(FakePlayerDriver::$calls);
        $this->assertNotNull($tag->fresh()->last_used_at);
    }

    public function test_the_device_is_the_tags_own_then_the_clients_first(): void
    {
        $this->albumTag();
        $this->postJson('/api/tags/04A31F92/play')->assertStatus(422)->assertJsonPath('error', 'no_device');

        $client = Client::create(['name' => 'Reader', 'type' => 'single', 'status' => 'approved', 'api_token' => 'reader-token', 'registration_token' => 'reg', 'pairing_code' => '123456', 'hardware_id' => 'hw-1']);
        $client->devices()->attach($this->device->id, ['sort_order' => 0]);
        $this->postJson('/api/tags/04A31F92/play', ['client' => 'reader-token'])->assertOk()->assertJsonPath('device.id', $this->device->id);

        PlayTag::query()->update(['device_id' => $this->device->id]);
        $this->postJson('/api/tags/04A31F92/play')->assertOk();
    }

    public function test_a_radio_tag_plays_the_station(): void
    {
        $station = RadioStation::create(['name' => 'Radio 1']);
        PlayTag::create(['uid' => 'RADIO1', 'target_type' => 'radio', 'target_id' => $station->id, 'device_id' => $this->device->id]);

        $this->postJson('/api/tags/radio1/play')->assertOk();
        $this->assertContains('playRadioStation', array_column(FakePlayerDriver::$calls, 0));
    }

    public function test_a_physical_item_plays_its_library_album_or_is_not_playable(): void
    {
        $track = $this->libraryTrack();
        $item = PhysicalItem::create(['artist' => 'Someone', 'title' => 'Something', 'medium' => 'cd']);
        PlayTag::create(['uid' => 'CD1', 'target_type' => 'physical', 'target_id' => $item->id, 'device_id' => $this->device->id]);

        $this->postJson('/api/tags/CD1/play')->assertStatus(422)->assertJsonPath('error', 'not_playable');

        $item->forceFill(['album_id' => $track->album_id])->save();
        $this->postJson('/api/tags/CD1/play')->assertOk();
    }

    public function test_a_removed_target_is_not_playable(): void
    {
        PlayTag::create(['uid' => 'GONE', 'target_type' => 'album', 'target_id' => 999, 'device_id' => $this->device->id]);

        $this->postJson('/api/tags/GONE/play')->assertStatus(422)->assertJsonPath('error', 'not_playable');
        $this->getJson('/api/tags/GONE')->assertJsonPath('data.target.exists', false);
    }

    public function test_an_unknown_tag_is_remembered_for_the_admin(): void
    {
        $this->postJson('/api/tags/aa:bb:cc/play')->assertNotFound()->assertJsonPath('error', 'unknown_tag')->assertJsonPath('uid', 'AABBCC');

        $this->assertSame('AABBCC', PlayTags::lastScanned()['uid']);
    }

    public function test_a_library_tag_is_refused_without_a_library_but_radio_plays(): void
    {
        $this->albumTag(['device_id' => $this->device->id]);
        $station = RadioStation::create(['name' => 'Radio 1']);
        PlayTag::create(['uid' => 'RADIO1', 'target_type' => 'radio', 'target_id' => $station->id, 'device_id' => $this->device->id]);
        config(['profile.current' => 'control']);

        $this->postJson('/api/tags/04A31F92/play')->assertStatus(422)->assertJsonPath('error', 'not_available_in_profile');
        $this->postJson('/api/tags/RADIO1/play')->assertOk();
    }

    public function test_managing_tags_needs_the_admin_token(): void
    {
        $track = $this->libraryTrack();
        $this->getJson('/api/admin/tags')->assertUnauthorized();
        $this->postJson('/api/admin/tags', ['target_type' => 'album', 'target_id' => $track->album_id])->assertUnauthorized();

        User::factory()->create();
        [, $token] = AdminToken::issue('test');
        $auth = ['Authorization' => 'Bearer '.$token];

        $id = $this->postJson('/api/admin/tags', ['target_type' => 'album', 'target_id' => $track->album_id, 'uid' => '04:a3'], $auth)
            ->assertCreated()->assertJsonPath('data.uid', '04A3')->json('data.id');
        $this->postJson('/api/admin/tags', ['target_type' => 'album', 'target_id' => $track->album_id, 'uid' => '04a3'], $auth)->assertStatus(422);
        $this->postJson('/api/admin/tags', ['target_type' => 'album', 'target_id' => 999], $auth)->assertStatus(422);

        $generated = $this->postJson('/api/admin/tags', ['target_type' => 'track', 'target_id' => $track->id], $auth)->assertCreated()->json('data.uid');
        $this->assertStringStartsWith('QR', $generated);

        $this->putJson("/api/admin/tags/{$id}", ['shuffle' => true, 'device_id' => $this->device->id], $auth)
            ->assertOk()->assertJsonPath('data.shuffle', true)->assertJsonPath('data.device.id', $this->device->id);
        $this->getJson('/api/admin/tags', $auth)->assertJsonCount(2, 'data');
        $this->deleteJson("/api/admin/tags/{$id}", [], $auth)->assertOk();
        $this->assertDatabaseMissing('play_tags', ['id' => $id]);
    }

    public function test_the_qr_page_plays_on_the_pinned_player_or_asks_which_device(): void
    {
        $this->albumTag();

        $this->get('/t/04A31F92')->assertOk()->assertSee('Where should it play?')->assertSee('Kitchen');
        $this->assertSame([], FakePlayerDriver::$calls);

        $this->get('/t/04A31F92?device='.$this->device->id)->assertOk()->assertSee('Playing on Kitchen.');
        $this->assertNotEmpty(FakePlayerDriver::$calls);

        FakePlayerDriver::reset();
        $this->app->forgetScopedInstances();
        $this->withCookie(SelectedDevice::COOKIE, (string) $this->device->id)->get('/t/04A31F92')->assertSee('Playing on Kitchen.');

        $this->get('/t/nothing')->assertOk()->assertSee('Unknown tag');
    }

    public function test_the_settings_page_adds_a_tag_for_the_admin(): void
    {
        $track = $this->libraryTrack();
        $this->get('/settings/tags')->assertRedirect();

        Livewire::test(PlayTagManager::class)->set('targetId', $track->album_id)->call('save')->assertForbidden();

        $this->postJson('/api/tags/AA11/play');

        Livewire::actingAs(User::factory()->create())->test(PlayTagManager::class)
            ->call('useLastScanned')->assertSet('uid', 'AA11')
            ->set('targetType', 'album')->set('search', mb_substr($track->album->name, 0, 3))
            ->call('choose', $track->album_id)
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('play_tags', ['uid' => 'AA11', 'target_type' => 'album', 'target_id' => $track->album_id]);
    }
}
