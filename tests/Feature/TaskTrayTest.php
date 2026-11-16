<?php

namespace Tests\Feature;

use App\Domain\Tasks\UserTasks;
use App\Livewire\TaskTray;
use App\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** The floating tray of tasks a user started. */
class TaskTrayTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_tray_shows_nothing(): void
    {
        Cache::flush();
        Livewire::test(TaskTray::class)->assertDontSee('role="status"', false);
    }

    public function test_running_export_shows_measured_progress_and_disappears_when_done(): void
    {
        $e = Export::create(['selection_type' => 'tracks', 'track_ids' => [1], 'profile' => 'mp3', 'status' => Export::RUNNING, 'files_total' => 4, 'files_done' => 1]);

        $tasks = UserTasks::active();
        $this->assertSame('export', $tasks[0]['kind']);
        $this->assertSame(25, $tasks[0]['percent']);

        $e->forceFill(['status' => Export::DONE])->save();
        $this->assertSame([], UserTasks::active());
    }

    public function test_cached_task_lifecycle_with_estimate_and_counter(): void
    {
        Cache::flush();
        UserTasks::put('deep:1:stems', 'deep_analysis', 'Stems: Song', 'queued', null, 100);
        $this->assertNull(UserTasks::active()[0]['percent']);
        $this->assertSame('queued', UserTasks::active()[0]['state']);

        UserTasks::running('deep:1:stems');
        $this->travel(50)->seconds();
        $t = UserTasks::active()[0];
        $this->assertSame(50, $t['percent']);
        $this->assertTrue($t['estimated']);
        UserTasks::done('deep:1:stems');
        $this->assertSame([], UserTasks::active());

        UserTasks::put('analysis:backlog', 'analysis', 'Audio analysis', 'queued', 2);
        UserTasks::advance('analysis:backlog');
        $this->assertSame(50, UserTasks::active()[0]['percent']);
        UserTasks::advance('analysis:backlog');
        $this->assertSame([], UserTasks::active());
    }
}
