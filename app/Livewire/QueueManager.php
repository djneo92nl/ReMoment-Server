<?php

namespace App\Livewire;

use App\Domain\Health\QueueAdmin;
use App\Domain\Health\QueueInspector;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** The queue tab of /settings/health: per-queue health, pause/resume, cancel and run-first per job class, pending and failed jobs, retry, worker restart, missing metadata. */
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

    public function pauseQueue(string $queue, QueueAdmin $admin): void
    {
        try {
            $admin->pause($queue);
            $this->toast("Queue {$queue} paused. A job that is running finishes; nothing new starts.");
        } catch (\InvalidArgumentException $e) {
            $this->toast($e->getMessage());
        }
    }

    public function resumeQueue(string $queue, QueueAdmin $admin): void
    {
        $admin->resume($queue);
        $this->toast("Queue {$queue} resumed.");
    }

    public function cancelJobs(string $queue, string $class, QueueAdmin $admin): void
    {
        $result = $admin->cancel($queue, $class);
        $this->toast("Cancelled {$result['cancelled']} waiting ".class_basename($class).' jobs.'.($result['skipped_running'] > 0 ? " {$result['skipped_running']} had just started and kept running." : ''));
    }

    public function prioritiseJobs(string $queue, string $class, QueueAdmin $admin): void
    {
        try {
            $moved = $admin->prioritise($queue, $class);
            $this->toast($moved > 0 ? "{$moved} ".class_basename($class).' jobs moved to the front of the queue.' : 'No waiting jobs of that kind to move.');
        } catch (\LogicException $e) {
            $this->toast($e->getMessage());
        }
    }

    private function toast(string $message): void
    {
        session()->flash('health-success', $message);
    }

    public function render(QueueInspector $inspector, QueueAdmin $admin)
    {
        try {
            $failedCount = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $failedCount = 0;
        }

        return view('livewire.queue-manager', [
            'queues' => $inspector->queues(),
            'canReorder' => $admin->overview()['can_reorder'],
            'pendingJobs' => $inspector->pending(),
            'failedJobs' => $inspector->failed(),
            'failedCount' => $failedCount,
        ]);
    }
}
