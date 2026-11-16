<?php

namespace App\Livewire;

use App\Domain\Tasks\UserTasks;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Floating tray of the tasks a user started (export, stems, analysis): progress while they work, gone when done. */
class TaskTray extends Component
{
    public function render(): View
    {
        return view('livewire.task-tray', ['tasks' => UserTasks::active()]);
    }
}
