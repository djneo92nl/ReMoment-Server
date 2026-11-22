<?php

namespace App\Livewire;

use App\Domain\Analysis\DeepAnalysis;
use App\Domain\Analysis\MoodCurve;
use App\Models\Media\Track;
use Livewire\Component;

/**
 * The mood over time of one library track (album page, "Track details", workstream 16.3): the stored intensity and
 * positivity strips with a line about the most and least intense section, and for the admin the "Analyse mood" request
 * with its queued / running / failed states (polled until the job is done). Guests only see what is stored. Same shape
 * as `TrackSections`; domain code is `DeepAnalysis` and `MoodCurve`.
 */
class TrackMood extends Component
{
    public int $trackId;

    public ?string $error = null;

    public function mount(int $trackId): void
    {
        $this->trackId = $trackId;
    }

    public function analyse(): void
    {
        abort_unless(auth()->check(), 403);

        $file = DeepAnalysis::fileFor($this->track());
        if ($file === null) {
            $this->error = 'This track has no own audio file on this server.';

            return;
        }

        $status = DeepAnalysis::request($file, ['mood'])['status'];
        $this->error = $status === 'not_available' ? 'The mood models are not available (the analysis sidecar needs its ML build).' : null;
    }

    public function remove(): void
    {
        abort_unless(auth()->check(), 403);

        if ($file = DeepAnalysis::fileFor($this->track())) {
            DeepAnalysis::forget($file, 'mood');
        }
    }

    private function track(): Track
    {
        return Track::findOrFail($this->trackId);
    }

    /** Lazy on the album page: nothing is queried for the closed panels of the other tracks. */
    public function placeholder(): string
    {
        return '<div class="text-gray-500 dark:text-gray-400">…</div>';
    }

    public function render()
    {
        $track = $this->track();
        $file = DeepAnalysis::fileFor($track);
        $state = $file ? DeepAnalysis::state($file, $file->analysis()->first(), 'mood') : null;
        $curve = $track->moodCurve();
        $sections = $track->sections()['sections'] ?? null;

        return view('livewire.track-mood', [
            'curve' => $curve,
            'summary' => $curve && $sections ? MoodCurve::summary(MoodCurve::perSection($curve, $sections)) : null,
            'state' => $state['state'] ?? null,
            'message' => $state['message'] ?? null,
            'canRequest' => auth()->check() && $file !== null && DeepAnalysis::available('mood'),
            'estimate' => $file ? DeepAnalysis::estimateSeconds('mood', (int) $track->duration) : null,
            'duration' => (int) $track->duration,
        ]);
    }
}
