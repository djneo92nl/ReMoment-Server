<?php

namespace App\Livewire;

use App\Domain\Analysis\StemWeights;
use Livewire\Component;

/**
 * The opt-in download of the Demucs weights, on /settings/analysis next to the other analysis settings: the licence
 * note, a button (admin) and the progress. Shown only when a stems service is configured (STEMS_URL).
 * See App\Domain\Analysis\StemWeights and docs/architecture/stems-service.md, "Weights".
 */
class StemWeightsCard extends Component
{
    /** The answer to the last click: shown above the state until the next one. */
    public ?string $notice = null;

    public ?string $noticeError = null;

    public function download(): void
    {
        abort_unless(auth()->check(), 403);

        [$status, $body] = StemWeights::download();

        $this->noticeError = $status >= 400 ? ($body['message'] ?? 'The stems service did not answer.') : null;
        $this->notice = $status >= 400 ? null : match ($body['state'] ?? null) {
            StemWeights::READY => 'The weights are already there.',
            default => 'The download started.',
        };
    }

    public function render()
    {
        $configured = StemWeights::configured();
        [$status, $weights] = $configured ? StemWeights::status() : [409, []];

        return view('livewire.stem-weights-card', [
            'configured' => $configured,
            'status' => $status,
            'weights' => $status === 200 ? $weights : null,
            'problem' => $configured && $status !== 200 ? ($weights['message'] ?? 'The stems service did not answer.') : null,
            'licenceNote' => config('analysis.stems_weights_licence_note'),
            'licenceUrl' => config('analysis.stems_weights_licence_url'),
        ]);
    }
}
