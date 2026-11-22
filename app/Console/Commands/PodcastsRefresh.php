<?php

namespace App\Console\Commands;

use App\Jobs\RefreshPodcast;
use App\Models\Podcast;
use Illuminate\Console\Command;

class PodcastsRefresh extends Command
{
    protected $signature = 'podcasts:refresh {--force : Ignore the interval and the back-off} {--podcast= : Only this podcast id} {--now : Fetch here instead of queueing}';

    protected $description = 'Check podcast feeds for new episodes (conditional GET, spaced out, retention applied)';

    public function handle(): int
    {
        $query = Podcast::query()->orderBy('next_check_at')->orderBy('id');

        if ($id = $this->option('podcast')) {
            $query->whereKey((int) $id);
        } elseif (!$this->option('force')) {
            $query->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()));
        }

        $podcasts = $query->limit((int) config('podcasts.batch'))->get();
        $gap = max(0, (int) config('podcasts.spacing_seconds'));

        foreach ($podcasts as $i => $podcast) {
            if ($this->option('now')) {
                $this->line("{$podcast->title}: ".\App\Domain\Podcasts\PodcastRefresher::refresh($podcast, (bool) $this->option('force')));

                continue;
            }

            RefreshPodcast::dispatch($podcast->id, (bool) $this->option('force'))->delay(now()->addSeconds($i * $gap));
        }

        $this->info($podcasts->count().($this->option('now') ? ' feeds checked.' : ' feeds queued.'));

        return self::SUCCESS;
    }
}
