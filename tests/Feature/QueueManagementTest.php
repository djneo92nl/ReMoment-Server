<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeat;
use App\Livewire\QueueManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Failed jobs and worker controls on /settings/health. */
class QueueManagementTest extends TestCase
{
    use RefreshDatabase;

    private function failedJob(string $class = 'App\\Jobs\\EnrichTrackLyrics'): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => $class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => $class, 'command' => serialize(new QueueHeartbeat)]]),
            'exception' => "Illuminate\\Http\\Client\\RequestException: HTTP request returned status code 429:\n#0 trace",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_failed_jobs_are_listed_with_their_error(): void
    {
        $id = $this->failedJob();

        Livewire::test(QueueManager::class)
            ->assertSee('Failed jobs')
            ->assertSee('EnrichTrackLyrics')
            ->assertSee('status code 429')
            ->assertSee('data-test="failed-'.$id.'"', false);
    }

    public function test_a_failed_job_can_be_forgotten_and_all_can_be_cleared(): void
    {
        $one = $this->failedJob();
        $this->failedJob();

        Livewire::test(QueueManager::class)->call('forgetFailed', $one);
        $this->assertSame(1, DB::table('failed_jobs')->count());

        Livewire::test(QueueManager::class)->call('flushFailed');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_retrying_puts_the_job_back_on_the_queue(): void
    {
        config(['queue.default' => 'database']);
        $id = $this->failedJob();

        Livewire::test(QueueManager::class)->call('retryFailed', $id);

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_workers_can_be_restarted_from_the_page(): void
    {
        Livewire::test(QueueManager::class)->call('restartWorkers')->assertSee('Workers told to restart');

        $this->assertNotNull(cache()->get('illuminate:queue:restart'));
    }
}
