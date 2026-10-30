<?php

namespace App\Livewire;

use App\Domain\Analysis\DeepAnalysis;
use App\Models\Media\Track;
use Livewire\Component;

/**
 * The sections of one library track under its waveform (album page, "Track details"): the stored strip, a plain
 * "no structure found" when the analysis looked and found none, and for the admin the "Analyse sections" request
 * with its queued / running / failed states (polled until the job is done). Guests only see what is stored.
 * Domain code is `DeepAnalysis`; the REST endpoints take the bearer token only, so this goes there directly.
 */
class TrackSections extends Component
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

        $status = DeepAnalysis::request($file, ['sections'])['status'];
        $this->error = $status === 'not_available' ? 'The structure service is not available.' : null;
    }

    public function remove(): void
    {
        abort_unless(auth()->check(), 403);

        if ($file = DeepAnalysis::fileFor($this->track())) {
            DeepAnalysis::forget($file, 'sections');
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
        $state = $file ? DeepAnalysis::state($file, $file->analysis()->first(), 'sections') : null;
        $stored = $track->sections();

        return view('livewire.track-sections', [
            'sections' => $stored['sections'] ?? null,
            'reason' => $stored['reason'] ?? null,
            'state' => $state['state'] ?? null,
            'message' => $state['message'] ?? null,
            'canRequest' => auth()->check() && $file !== null && DeepAnalysis::anyAvailable(),
            'estimate' => $file ? DeepAnalysis::estimateSeconds('sections', (int) $track->duration) : null,
            'duration' => (int) $track->duration,
        ]);
    }
}
