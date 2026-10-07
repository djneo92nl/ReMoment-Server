<?php

namespace Tests\Feature;

use App\Livewire\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SystemHealthPendingJobsTest extends TestCase
{
    use RefreshDatabase;

    private function job(string $class, array $extra = []): array
    {
        return array_merge([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $class]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->subMinutes(5)->timestamp,
        ], $extra);
    }

    public function test_pending_database_jobs_are_grouped_by_class_with_counts(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            $this->job('App\\Jobs\\ProcessArtwork'),
            $this->job('App\\Jobs\\ProcessArtwork'),
            $this->job('App\\Jobs\\ProcessArtwork', ['reserved_at' => now()->timestamp]),
            $this->job('App\\Jobs\\ScrobbleToLastfm', ['available_at' => now()->addHour()->timestamp]),
        ]);

        Livewire::test(SystemHealth::class)
            ->assertSee('Pending jobs')
            ->assertSee('4 in queue')
            ->assertSee('ProcessArtwork')
            ->assertSee('2 waiting · 1 running · 0 delayed')
            ->assertSee('0 waiting · 0 running · 1 delayed');
    }
}
