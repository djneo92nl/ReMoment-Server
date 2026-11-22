<?php

namespace App\Livewire;

use App\Domain\Analysis\DeepAnalysis;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\SimilarSections;
use App\Models\Media\Track;
use Livewire\Component;

/**
 * "Sections that sound like this one" (album page, "Track details", workstream 16.5): pick a section of the track and see
 * the best matching section of each other track whose section sound was analysed, with the time range and how alike the
 * sound is. For the admin it also has the "Analyse the sound of the sections" request (kind `section_sound`, needs the
 * sections) with its queued / running / failed states. Domain: `SimilarSections`, `DeepAnalysis`.
 */
class SectionSimilar extends Component
{
    public int $trackId;

    /** The index of the chosen section. */
    public int $section = 0;

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

        $status = DeepAnalysis::request($file, ['section_sound'])['status'];
        $this->error = $status === 'not_available' ? 'The sound models are not available (the analysis sidecar needs its ML build).' : null;
    }

    public function remove(): void
    {
        abort_unless(auth()->check(), 403);

        if ($file = DeepAnalysis::fileFor($this->track())) {
            DeepAnalysis::forget($file, 'section_sound');
        }
    }

    private function track(): Track
    {
        return Track::findOrFail($this->trackId);
    }

    public function placeholder(): string
    {
        return '<div class="text-gray-500 dark:text-gray-400">…</div>';
    }

    public function render()
    {
        $track = $this->track();
        $file = DeepAnalysis::fileFor($track);
        $state = $file ? DeepAnalysis::state($file, $file->analysis()->first(), 'section_sound') : null;
        $similar = new SimilarSections($track, LibrarySources::hiddenForRequest(null));
        $sections = $similar->sections();
        $rows = [];

        if ($sections !== []) {
            $index = collect($sections)->contains('index', $this->section) ? $this->section : $sections[0]['index'];
            $matches = $similar->matches($index, 8) ?? [];
            $tracks = Track::query()->with(['artist', 'album'])->findMany(array_column($matches, 'track_id'))->keyBy('id');
            foreach ($matches as $m) {
                if ($t = $tracks[$m['track_id']] ?? null) {
                    $rows[] = ['track' => $t, 'section' => $m['section'], 'similarity' => (int) round(max(0, $m['similarity']) * 100)];
                }
            }
        }

        return view('livewire.section-similar', [
            'sections' => $sections,
            'rows' => $rows,
            'hasSections' => !empty($track->sections()['sections']),
            'state' => $state['state'] ?? null,
            'message' => $state['message'] ?? null,
            'canRequest' => auth()->check() && $file !== null && DeepAnalysis::available('section_sound'),
            'estimate' => $file ? DeepAnalysis::estimateSeconds('section_sound', (int) $track->duration) : null,
        ]);
    }
}
