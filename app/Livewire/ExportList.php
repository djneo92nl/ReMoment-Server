<?php

namespace App\Livewire;

use App\Domain\Export\ExportRetention;
use App\Domain\Export\ExportService;
use App\Livewire\Concerns\ManagesExports;
use App\Models\Export;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Recent exports with progress, download links, cancel and remove (Settings › Files › Exports). Admin only. */
class ExportList extends Component
{
    use ManagesExports;

    public function render(): View
    {
        $service = app(ExportService::class);
        $exports = Export::query()->latest('id')->limit(20)->get();

        return view('livewire.export-list', [
            'exports' => $exports->map(fn (Export $e) => $service->resource($e))->all(),
            'active' => $exports->contains(fn (Export $e) => in_array($e->status, [Export::QUEUED, Export::RUNNING], true)),
            'used' => auth()->check() ? app(ExportRetention::class)->size() : 0,
        ]);
    }
}
