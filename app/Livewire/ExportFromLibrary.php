<?php

namespace App\Livewire;

use App\Domain\Profile\Profile;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Export page builder: the library picker on top, and once something is picked the export dialog (quality, estimate,
 * start) for exactly those tracks. Admin only like the dialog; the export itself goes through `ExportService`.
 */
class ExportFromLibrary extends Component
{
    /** @var list<array{type: string, id: int}> */
    public array $picks = [];

    /** @var list<int> */
    #[Locked]
    public array $trackIds = [];

    #[Locked]
    public int $version = 0;

    #[On('library-picked')]
    public function picked(string $name, array $trackIds): void
    {
        abort_unless(auth()->check() && Profile::allows('library') && Profile::allows('file_storage'), 403);
        if ($name !== 'export') {
            return;
        }

        $this->trackIds = array_values(array_unique(array_map('intval', $trackIds)));
        $this->version++;
    }

    public function render(): View
    {
        return view('livewire.export-from-library');
    }
}
