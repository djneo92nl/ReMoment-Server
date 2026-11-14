<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The listening insights cards on /stats. */
class StatsInsightsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_insights_cards_show_and_survive_empty_data(): void
    {
        Cache::flush();
        config(['profile.current' => 'full']);

        $this->get('/stats')->assertOk()->assertSee('New vs Repeat')->assertSee('Most Skipped Artists')->assertSee('Late at Night');
    }

    public function test_control_has_no_insight_cards(): void
    {
        Cache::flush();
        config(['profile.current' => 'control']);

        $this->get('/stats')->assertOk()->assertDontSee('New vs Repeat');
    }
}
