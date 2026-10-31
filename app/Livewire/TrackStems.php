<?php

namespace App\Livewire;

use App\Domain\Analysis\DeepAnalysis;
use App\Models\Media\Track;
use Livewire\Component;

/**
 * The stems of one library track under its sections (album page, "Track details"): a lane per stem from the stored
 * loudness curves, the vocal share and the instrumental ranges, and for the admin the "Analyse stems" request with its
 * queued / running / failed states (polled until the job is done) and, when the stems service is not set up, a hint.
 * Guests only see what is stored. Same shape as `TrackSections`; domain code is `DeepAnalysis`.
 */
class TrackStems extends Component
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

        $status = DeepAnalysis::request($file, ['stems'])['status'];
        $this->error = $status === 'not_available' ? 'The stems service is not available.' : null;
    }

    public function remove(): void
    {
        abort_unless(auth()->check(), 403);

        if ($file = DeepAnalysis::fileFor($this->track())) {
            DeepAnalysis::forget($file, 'stems');
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

    /** "about 90 s" or "about 3 min": the estimate as a person reads it. */
    private static function approx(int $seconds): string
    {
        return $seconds < 120 ? "about {$seconds} s" : 'about '.round($seconds / 60).' min';
    }

    public function render()
    {
        $track = $this->track();
        $file = DeepAnalysis::fileFor($track);
        $state = $file ? DeepAnalysis::state($file, $file->analysis()->first(), 'stems') : null;
        $stored = $track->stems();
        $stage = DeepAnalysis::stage('stems');
        $available = $file !== null && DeepAnalysis::available('stems');

        return view('livewire.track-stems', [
            'stems' => $stored['stems'] ?? null,
            'vocalRatio' => $stored['vocal_ratio'] ?? null,
            'instrumental' => $stored['instrumental'] ?? [],
            'sections' => $track->sections()['sections'] ?? null,
            'state' => $state['state'] ?? null,
            'message' => $state['message'] ?? null,
            'canRequest' => auth()->check() && $available,
            'hint' => auth()->check() && $file !== null && !$available,
            'estimate' => $file ? self::approx(DeepAnalysis::estimateSeconds('stems', (int) $track->duration)) : null,
            'ram' => number_format(($stage->estimateRamMb() ?? 0) / 1000, 1),
            'duration' => (int) $track->duration,
        ]);
    }
}
