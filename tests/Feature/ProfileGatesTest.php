<?php

namespace Tests\Feature;

use App\Domain\Alerts\AlertChecks;
use App\Domain\Health\Queues;
use App\Domain\Profile\Profile;
use App\Listeners\Device\StorePlaybackHistory;
use App\Models\StorageLocation;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** What each profile does and does not run: scheduler, heartbeat queues, alert checks, history queue, navigation. */
class ProfileGatesTest extends TestCase
{
    use RefreshDatabase;

    private function profile(string $profile): void
    {
        config(['profile.current' => $profile]);
    }

    /** @return list<string> the scheduled commands and tasks that would run now */
    private function scheduled(): array
    {
        $this->app->make(Kernel::class)->call('list');   // loads routes/console.php

        return collect(app(Schedule::class)->events())
            ->filter(fn (Event $e) => $e->filtersPass($this->app))
            ->map(fn (Event $e) => $e->command ? trim(preg_replace('/^.*artisan[\'"]? /', '', $e->command)) : (string) $e->description)
            ->values()->all();
    }

    private const BASE = ['device:sync-sources', 'radio:refresh', 'radio:fix-artwork', 'radio-plays-prune', 'backup:run', 'queue:prune-failed --hours=720', 'health-heartbeat', 'queue-watchdog', 'alerts:check', 'alerts-prune'];

    public function test_control_schedules_only_the_base(): void
    {
        $this->profile('control');
        $scheduled = $this->scheduled();

        foreach (['library:warm', 'library:analyze', 'catalogue:sync', 'catalogue:new-releases', 'catalogue:availability', 'catalogue:reconcile',
            'library:enrich', 'artwork:backfill', 'artwork:prerender', 'playlists:refresh-smart', 'library:merge-duplicates --force', 'library:scan-files --queue'] as $gated) {
            $this->assertNotContains($gated, $scheduled, "{$gated} must not run in Control");
        }
        foreach (self::BASE as $base) {
            $this->assertContains($base, $scheduled, "{$base} must run in Control");
        }
    }

    public function test_standard_schedules_the_library_but_not_analysis(): void
    {
        $this->profile('standard');
        $scheduled = $this->scheduled();

        foreach (['library:warm', 'catalogue:sync', 'library:enrich', 'library:scan-files --queue', 'artwork:prerender'] as $command) {
            $this->assertContains($command, $scheduled);
        }
        $this->assertNotContains('library:analyze', $scheduled);
    }

    public function test_full_schedules_everything(): void
    {
        $this->profile('full');
        $scheduled = $this->scheduled();

        foreach (['library:analyze', 'library:warm', 'catalogue:sync', ...self::BASE] as $command) {
            $this->assertContains($command, $scheduled);
        }
    }

    public function test_nothing_but_the_base_is_scheduled_in_control(): void
    {
        $this->profile('control');

        // A library task added to routes/console.php and forgotten in the gate shows up here.
        $this->assertSame([], array_values(array_diff($this->scheduled(), [...self::BASE, 'device:discover'])));
    }

    public function test_heartbeat_queues_follow_the_workers_a_profile_starts(): void
    {
        $this->profile('control');
        $this->assertSame(['health', 'history'], Queues::heartbeat());
        $this->assertNotContains('storage', Queues::all());
        $this->assertContains('history', Queues::all());

        $this->profile('standard');
        $this->assertContains('storage', Queues::heartbeat());
        $this->assertContains('heavy', Queues::heartbeat());
        $this->assertNotContains(config('analysis.queue'), Queues::heartbeat());

        $this->profile('full');
        $this->assertContains(config('analysis.queue'), Queues::heartbeat());
        $this->assertContains(Queues::stems(), Queues::heartbeat());
    }

    public function test_the_storage_alert_check_is_off_in_control(): void
    {
        Http::fake();
        Cache::flush();
        StorageLocation::create(['name' => 'Music', 'driver' => 'local', 'config' => ['path' => '/definitely/not/here'], 'role' => 'library']);

        $this->profile('control');
        (new AlertChecks)->storage();
        $this->assertSame(0, \App\Models\Alert::count());
    }

    public function test_history_is_written_on_its_own_queue_only_in_control(): void
    {
        $this->profile('control');
        $this->assertSame('history', (new StorePlaybackHistory)->viaQueue());

        $this->profile('full');
        $this->assertNull((new StorePlaybackHistory)->viaQueue());
    }

    public function test_the_navigation_hides_the_library_in_control(): void
    {
        $this->profile('control');
        $html = $this->get('/devices')->assertOk()->getContent();
        $this->assertStringNotContainsString('href="'.route('library.index').'"', $html);
        $this->assertStringContainsString('href="'.route('radio.index').'"', $html);
        $this->assertStringContainsString('href="'.route('devices.index').'"', $html);

        $this->profile('standard');
        $html = $this->get('/devices')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('library.index').'"', $html);
    }
}
