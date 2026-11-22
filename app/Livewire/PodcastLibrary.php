<?php

namespace App\Livewire;

use App\Domain\Podcasts\DeviceUnreachable;
use App\Domain\Podcasts\EpisodePlayback;
use App\Domain\Podcasts\InvalidFeedException;
use App\Domain\Podcasts\PodcastRefresher;
use App\Domain\Podcasts\Podcasts;
use App\Models\Podcast;
use App\Models\PodcastEpisode;
use App\Support\SelectedDevice;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /library/podcasts (workstream 8.2, docs/frontend/podcasts.md): the subscriptions, the episodes of one of them (or the new
 * episodes of all), play on the pinned player and mark played. Open like playback; adding, refreshing and removing a
 * subscription need the admin login, as the admin API does.
 */
class PodcastLibrary extends Component
{
    private const PAGE = 25;

    #[Url(as: 'podcast')]
    public ?int $selected = null;

    public bool $unplayed = false;

    public int $shown = self::PAGE;

    public string $feedUrl = '';

    public ?string $notice = null;

    public bool $noticeIsError = false;

    public function select(?int $id): void
    {
        $this->selected = $id;
        $this->shown = self::PAGE;
        $this->notice = null;
    }

    public function more(): void
    {
        $this->shown += self::PAGE;
    }

    public function subscribe(): void
    {
        abort_unless(auth()->check(), 403);
        $this->validate(['feedUrl' => ['required', 'string', 'max:2000']]);

        try {
            [$podcast, $created] = Podcasts::subscribe($this->feedUrl);
        } catch (InvalidFeedException $e) {
            $this->say($e->getMessage(), true);

            return;
        }

        $this->feedUrl = '';
        $this->say($created ? "Subscribed to {$podcast->title}." : "Already subscribed to {$podcast->title}.");
    }

    public function refresh(int $id): void
    {
        abort_unless(auth()->check(), 403);

        if (!$podcast = Podcast::find($id)) {
            return;
        }

        if ($podcast->last_checked_at?->gt(now()->subMinutes((int) config('podcasts.manual_interval')))) {
            $this->say('Checked a moment ago; try again in a few minutes.', true);

            return;
        }

        $result = PodcastRefresher::refresh($podcast, force: true);
        $this->say($result === 'failed' ? 'The feed could not be read: '.$podcast->refresh()->last_error : 'Checked for new episodes.', $result === 'failed');
    }

    public function unsubscribe(int $id): void
    {
        abort_unless(auth()->check(), 403);

        if ($podcast = Podcast::find($id)) {
            Podcasts::unsubscribe($podcast);
            $this->selected === $id && $this->select(null);
            $this->say("Removed {$podcast->title}.");
        }
    }

    public function play(int $id): void
    {
        $this->notice = null;

        if (!$episode = PodcastEpisode::with('podcast')->find($id)) {
            return;
        }

        if (($device = app(SelectedDevice::class)->device()) === null) {
            $this->say('Pick a player in the bar below first.', true);

            return;
        }

        try {
            EpisodePlayback::play($device, $episode);
        } catch (DeviceUnreachable) {
            $this->say("{$device->name} is not reachable.", true);
        } catch (\Throwable $e) {
            $this->say($e->getMessage(), true);
        }
    }

    public function togglePlayed(int $id): void
    {
        if ($episode = PodcastEpisode::find($id)) {
            Podcasts::markPlayed($episode, $episode->played_at === null);
        }
    }

    public function markAllPlayed(int $id): void
    {
        if ($podcast = Podcast::find($id)) {
            Podcasts::markAllPlayed($podcast);
        }
    }

    private function say(string $message, bool $error = false): void
    {
        $this->notice = $message;
        $this->noticeIsError = $error;
    }

    public function render()
    {
        $podcasts = Podcast::query()
            ->withCount(['episodes', 'episodes as unplayed_count' => fn ($q) => $q->whereNull('played_at')])
            ->withMax('episodes as latest_episode_at', 'published_at')
            ->orderBy('title')->get();

        $current = $this->selected ? $podcasts->firstWhere('id', $this->selected) : null;
        $page = Podcasts::episodes($current, $this->unplayed || $current === null, $this->shown, null);

        return view('livewire.podcast-library', [
            'podcasts' => $podcasts->map(fn (Podcast $p) => $p->apiItem())->all(),
            'current' => $current?->apiItem(),
            'episodes' => $page['data'],
            'hasMore' => $page['next_cursor'] !== null,
            'isAdmin' => auth()->check(),
        ]);
    }
}
