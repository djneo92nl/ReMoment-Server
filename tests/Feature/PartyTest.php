<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Domain\Party\Party;
use App\Livewire\DevicePartySettings;
use App\Livewire\PartyQueue;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakePlayerDriver;
use Tests\Support\FakeQueueEditDriver;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

class PartyTest extends TestCase
{
    use MakesLibraryTracks, RefreshDatabase;

    private Device $device;

    private string $token;

    /** @var Track[] */
    private array $tracks = [];

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        $this->device = Device::factory()->driver(FakeQueueEditDriver::class)->inState(State::Playing)->create(['device_name' => 'Living room']);
        $this->token = Party::enable($this->device, 3, 10)['token'];

        $album = Album::create(['name' => 'Dummy', 'artist_id' => Artist::create(['name' => 'Portishead'])->id]);
        foreach (['Sour Times', 'Glory Box', 'Roads', 'Numb', 'Wandering Star'] as $name) {
            $this->tracks[] = $this->makeLibraryTrack($album, $name);
        }
    }

    private function page()
    {
        return Livewire::test(PartyQueue::class, ['token' => $this->token, 'visitor' => 'guest-1']);
    }

    private function added(): array
    {
        return array_values(array_filter(FakePlayerDriver::$calls, fn ($call) => $call[0] === 'addToQueue'));
    }

    public function test_the_page_opens_only_with_the_current_link(): void
    {
        $this->get('/party/'.$this->token)->assertOk()->assertSee('Add a track')->assertSee('Living room')->assertCookie('remoment_party_visitor');
        $this->get('/party/'.str_repeat('x', 32))->assertNotFound();

        $new = Party::rotate($this->device)['token'];
        $this->get('/party/'.$this->token)->assertNotFound();
        $this->get('/party/'.$new)->assertOk();

        Party::disable($this->device);
        $this->get('/party/'.$new)->assertNotFound();
    }

    public function test_a_guest_finds_playable_tracks_and_adds_one_to_the_queue(): void
    {
        $unplayable = $this->makeLibraryTrack($this->tracks[0]->album, 'Sour Grapes', dlna: false);

        $this->page()->set('search', 'sour')
            ->assertSee('Sour Times')->assertDontSee('Sour Grapes')
            ->call('add', $this->tracks[0]->id)->assertSet('problem', null)->assertSee('Added: Sour Times');

        $this->assertCount(1, $this->added());

        $this->page()->call('add', $unplayable->id)->assertSet('problem', 'That track cannot be played here.');
        $this->assertCount(1, $this->added());
    }

    public function test_a_search_by_artist_finds_tracks(): void
    {
        $this->page()->set('search', 'portis')->assertSee('Glory Box');
    }

    public function test_a_guest_is_limited_and_another_guest_is_not(): void
    {
        $page = $this->page();
        foreach ([0, 1, 2] as $i) {
            $page->call('add', $this->tracks[$i]->id)->assertSet('problem', null);
        }

        $page->call('add', $this->tracks[3]->id);
        $this->assertStringContainsString('You can add another track in', $page->get('problem'));
        $this->assertCount(3, $this->added());

        Livewire::test(PartyQueue::class, ['token' => $this->token, 'visitor' => 'guest-2'])
            ->call('add', $this->tracks[3]->id)->assertSet('problem', null);
        $this->assertCount(4, $this->added());
    }

    public function test_the_same_track_is_not_added_twice_in_a_row(): void
    {
        $this->page()->call('add', $this->tracks[0]->id);

        Livewire::test(PartyQueue::class, ['token' => $this->token, 'visitor' => 'guest-2'])
            ->call('add', $this->tracks[0]->id)->assertSet('problem', 'That track was added a moment ago.');
        $this->assertCount(1, $this->added());
    }

    public function test_an_open_page_stops_working_when_the_party_is_switched_off(): void
    {
        $page = $this->page();
        Party::disable($this->device);

        $page->call('add', $this->tracks[0]->id)->assertSet('problem', 'The party is over.');
        $this->assertCount(0, $this->added());
    }

    public function test_the_page_has_no_controls_but_add(): void
    {
        $actions = array_diff(get_class_methods(PartyQueue::class), get_class_methods(\Livewire\Component::class));

        $this->assertSame(['add'], array_values(array_filter($actions, fn ($method) => (new \ReflectionMethod(PartyQueue::class, $method))->isPublic() && !in_array($method, ['mount', 'render'], true))));
    }

    public function test_the_page_is_gated_with_the_library(): void
    {
        config(['profile.current' => 'control']);

        $this->get('/party/'.$this->token)->assertRedirect();
    }

    public function test_only_the_admin_switches_the_party_on(): void
    {
        $other = Device::factory()->driver(FakeQueueEditDriver::class)->create();

        Livewire::test(DevicePartySettings::class, ['device' => $other])->call('toggle')->assertForbidden();
        $this->assertFalse(Party::for($other)['enabled']);

        Livewire::actingAs(User::factory()->create())->test(DevicePartySettings::class, ['device' => $other])
            ->set('limit', 5)->call('toggle')->assertSee('/party/');
        $party = Party::for($other);
        $this->assertTrue($party['enabled']);
        $this->assertSame(5, $party['limit']);
        $this->assertSame($other->id, Party::deviceForToken($party['token'])->id);
    }
}
