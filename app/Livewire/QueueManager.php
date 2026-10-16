<?php

namespace App\Livewire;

use App\Domain\Health\QueueInspector;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** The queue tab of /settings/health: pending and failed jobs, retry, worker restart, missing metadata. */
class QueueManager extends Component
{
    public function retryFailed(string $id, QueueInspector $inspector): void
    {
        $inspector->retry($id);
        $this->toast('Job put back on the queue.');
    }

    public function retryAllFailed(QueueInspector $inspector): void
    {
        $inspector->retry('all');
        $this->toast('All failed jobs put back on the queue.');
    }

    public function forgetFailed(string $id, QueueInspector $inspector): void
    {
        $inspector->forget($id);
    }

    public function flushFailed(QueueInspector $inspector): void
    {
        $inspector->flushFailed();
        $this->toast('Failed jobs cleared.');
    }

    public function restartWorkers(QueueInspector $inspector): void
    {
        $inspector->restartWorkers();
        $this->toast('Workers told to restart after their current job.');
    }

    public function queueMissingEnrichment(QueueInspector $inspector): void
    {
        $records = $inspector->queueMissingEnrichment();
        $this->toast($records > 0 ? "Looked at {$records} tracks and queued what they still miss." : 'Nothing left to fetch.');
    }

    private function toast(string $message): void
    {
        session()->flash('health-success', $message);
    }

    public function render(QueueInspector $inspector)
    {
        try {
            $failedCount = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $failedCount = 0;
        }

        return view('livewire.queue-manager', [
            'pendingJobs' => $inspector->pending(),
            'failedJobs' => $inspector->failed(),
            'failedCount' => $failedCount,
        ]);
    }
}
