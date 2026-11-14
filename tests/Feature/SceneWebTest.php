<?php

namespace Tests\Feature;

use App\Domain\Device\State;
use App\Jobs\RunScene;
use App\Livewire\SceneEditor;
use App\Livewire\SceneList;
use App\Mcp\ApiBridge;
use App\Models\Device;
use App\Models\Scene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakePlayerDriver;
use Tests\TestCase;

/** The scenes pages (workstream 8.1b): list with Run, and the editor built from the operations' schemas. */
class SceneWebTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        FakePlayerDriver::reset();
        $this->device = Device::factory()->inState(State::Playing)->create(['device_name' => 'Kitchen']);
    }

    private function scene(array $steps, array $attributes = []): Scene
    {
        return Scene::create(['name' => 'Evening', 'icon' => 'moon', 'color' => '#336699', 'steps' => $steps] + $attributes);
    }

    private function volume(int $level): array
    {
        return ['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => $level]];
    }

    /**
     * Livewire's test harness switches all middleware off, including the route-model binding the API needs, so
     * the component tests answer the steps from a fake bridge; SceneTest runs scenes through the real API.
     */
    private function fakeBridge(array $statuses = []): void
    {
        $this->app->instance(ApiBridge::class, new class($statuses) extends ApiBridge
        {
            public function __construct(private array $statuses)
            {
                parent::__construct(app('router'));
            }

            public function call(string $method, string $path, array $params = []): array
            {
                $status = array_shift($this->statuses) ?? 200;

                return [$status, $status < 300 ? ['status' => 'ok'] : ['error' => 'unreachable', 'message' => 'Device is not reachable.']];
            }
        });
    }

    public function test_pages_render_for_guests_and_the_nav_links_them(): void
    {
        $scene = $this->scene([$this->volume(20)]);

        $this->get('/scenes')->assertOk()->assertSee('Evening')->assertSee(route('scenes.create'), false);
        $this->get('/scenes/create')->assertOk()->assertSee('Add action');
        $this->get("/scenes/{$scene->id}/edit")->assertOk()->assertSee('Evening');
        $this->get('/devices')->assertSee(route('scenes.index'), false);
    }

    public function test_run_executes_a_scene_and_reports_it(): void
    {
        $scene = $this->scene([$this->volume(41)]);

        $this->fakeBridge();

        Livewire::test(SceneList::class)->call('run', $scene->id)->assertSee('ran.');

        $this->assertSame('ok', $scene->fresh()->last_run_status);
    }

    public function test_run_reports_failed_steps(): void
    {
        $scene = $this->scene([$this->volume(41), $this->volume(5)]);
        $this->fakeBridge([200, 503]);

        Livewire::test(SceneList::class)->call('run', $scene->id)->assertSet('feedbackIsError', true)->assertSee('1 of 2 steps failed');
    }

    public function test_run_queues_a_scene_with_delays(): void
    {
        Queue::fake();
        $scene = $this->scene([$this->volume(41), ['type' => 'delay', 'seconds' => 3]]);

        Livewire::test(SceneList::class)->call('run', $scene->id)->assertSee('background');

        Queue::assertPushed(RunScene::class);
    }

    public function test_delete(): void
    {
        $scene = $this->scene([$this->volume(1)]);

        Livewire::test(SceneList::class)->call('delete', $scene->id);

        $this->assertSame(0, Scene::count());
    }

    public function test_the_editor_creates_a_scene_from_the_form(): void
    {
        Livewire::test(SceneEditor::class)
            ->set('name', 'Night')
            ->set('icon', 'moon')
            ->set('color', SceneEditor::COLORS['indigo'])
            ->set('stopOnError', true)
            ->call('addOperation')
            ->set('steps.0.operation', 'setVolume')
            ->set('steps.0.device_id', (string) $this->device->id)
            ->set('steps.0.params.volume', '15')
            ->call('addDelay')
            ->set('steps.1.seconds', '4')
            ->set('scheduleEnabled', true)
            ->set('scheduleTime', '22:30')
            ->set('scheduleDays', ['1', '5'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('scenes.index'));

        $scene = Scene::firstOrFail();
        $this->assertSame('Night', $scene->name);
        $this->assertTrue($scene->stop_on_error);
        $this->assertSame([1, 5], $scene->schedule_days);
        $this->assertSame('22:30', $scene->schedule_time);
        $this->assertSame([
            ['operation' => 'setVolume', 'device_id' => $this->device->id, 'params' => ['volume' => 15]],
            ['type' => 'delay', 'seconds' => 4],
        ], $scene->steps);
    }

    public function test_the_editor_shows_step_errors_and_saves_nothing(): void
    {
        Livewire::test(SceneEditor::class)
            ->set('name', 'Bad')
            ->call('addOperation')
            ->set('steps.0.operation', 'setVolume')
            ->set('steps.0.device_id', (string) $this->device->id)
            ->set('steps.0.params.volume', '500')
            ->call('save')
            ->assertNoRedirect()
            ->assertSee('volume');

        $this->assertSame(0, Scene::count());
    }

    public function test_the_editor_needs_a_name_and_a_time_for_a_schedule(): void
    {
        Livewire::test(SceneEditor::class)->call('save')->assertHasErrors(['name']);
        Livewire::test(SceneEditor::class)->set('name', 'X')->set('scheduleEnabled', true)->call('save')->assertHasErrors(['scheduleTime']);
    }

    public function test_the_editor_loads_and_updates_a_scene_and_changing_the_action_clears_params(): void
    {
        $scene = $this->scene([$this->volume(20)], ['schedule_enabled' => true, 'schedule_time' => '07:00', 'schedule_days' => [2]]);

        Livewire::test(SceneEditor::class, ['sceneId' => $scene->id])
            ->assertSet('name', 'Evening')
            ->assertSet('steps.0.params.volume', '20')
            ->assertSet('scheduleDays', [2])
            ->set('steps.0.operation', 'setMute')
            ->assertSet('steps.0.params', [])
            ->set('steps.0.params.muted', 'true')
            ->set('name', 'Morning')
            ->call('save')
            ->assertHasNoErrors();

        $scene->refresh();
        $this->assertSame('Morning', $scene->name);
        $this->assertSame(['operation' => 'setMute', 'device_id' => $this->device->id, 'params' => ['muted' => true]], $scene->steps[0]);
    }

    public function test_steps_can_be_moved_and_removed(): void
    {
        $c = Livewire::test(SceneEditor::class)->call('addDelay')->call('addOperation')->call('moveStep', 1, -1);
        $this->assertSame('operation', $c->get('steps.0.kind'));

        $c->call('removeStep', 0)->assertCount('steps', 1);
    }

    public function test_playlist_and_station_pickers_list_names_and_save_the_id(): void
    {
        $playlist = \App\Models\Media\Playlist::create(['name' => 'Sunday jazz']);
        $station = \App\Models\RadioStation::create(['name' => 'Radio Test']);

        $c = Livewire::test(SceneEditor::class)
            ->set('name', 'Radio')
            ->call('addOperation')
            ->set('steps.0.operation', 'playPlaylist')
            ->assertSee('Sunday jazz')
            ->set('steps.0.device_id', (string) $this->device->id)
            ->set('steps.0.params.playlist_id', (string) $playlist->id)
            ->call('addOperation')
            ->set('steps.1.operation', 'playRadio')
            ->assertSee('Radio Test')
            ->set('steps.1.device_id', (string) $this->device->id)
            ->set('steps.1.params.station', (string) $station->id)
            ->call('save')
            ->assertHasNoErrors();

        $steps = Scene::firstOrFail()->steps;
        $this->assertSame($playlist->id, $steps[0]['params']['playlist_id']);
        $this->assertSame($station->id, $steps[1]['params']['station']);
    }

    public function test_a_picker_keeps_a_deleted_record_visible(): void
    {
        $scene = $this->scene([['operation' => 'playRadio', 'device_id' => $this->device->id, 'params' => ['station' => 4242]]]);

        Livewire::test(SceneEditor::class, ['sceneId' => $scene->id])->assertSee('Missing (#4242)');
    }

    public function test_control_has_no_playlist_picker(): void
    {
        \App\Models\Media\Playlist::create(['name' => 'Sunday jazz']);
        config(['profile.current' => 'control']);

        Livewire::test(SceneEditor::class)->call('addOperation')->set('steps.0.operation', 'playPlaylist')->assertDontSee('Sunday jazz');
    }
}
