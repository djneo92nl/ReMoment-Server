<?php

namespace App\Livewire;

use App\Domain\Analysis\AnalysisAdmin;
use App\Domain\Health\QueueInspector;
use App\Models\File;
use Livewire\Component;

/** /settings/analysis: how far each analysis stage is, what runs now, what failed, and buttons to nudge it. */
class AnalysisStatus extends Component
{
    /** Stage key to show problems for; empty for all. */
    public string $stage = '';

    public function runBacklog(AnalysisAdmin $admin): void
    {
        $queued = $admin->runBacklog();
        $this->toast($queued > 0 ? "Queued {$queued} files." : 'Nothing left to analyse.');
    }

    public function analyse(AnalysisAdmin $admin, int $fileId): void
    {
        $file = File::find($fileId);

        if ($file && $admin->analyse($file)) {
            $this->toast("Queued {$file->path}.");
        }
    }

    /** Forgets a stage's error (and its attempts) and queues just that stage again. */
    public function retry(AnalysisAdmin $admin, int $fileId, string $stage): void
    {
        $file = File::find($fileId);

        if ($file && $admin->retry($file, $stage)) {
            $this->toast("Queued {$stage} for {$file->path}.");
        }
    }

    private function toast(string $message): void
    {
        session()->flash('analysis-success', $message);
    }

    public function render(AnalysisAdmin $admin, QueueInspector $inspector)
    {
        return view('livewire.analysis-status', $admin->summary($inspector) + ['problems' => $admin->problems($this->stage)]);
    }
}
