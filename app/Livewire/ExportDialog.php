<?php

namespace App\Livewire;

use App\Domain\Export\ExportRefused;
use App\Domain\Export\ExportService;
use App\Domain\Profile\Profile;
use App\Livewire\Concerns\ManagesExports;
use App\Models\Export;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Export button and panel on the album, artist and playlist pages (workstream 13.4): choose a profile, see the
 * estimate (size, parts, copied, transcoded, skipped), start, follow the progress, download the signed parts.
 * Admin only; everything goes through `Domain\Export\ExportService`, the same code as the REST API.
 */
class ExportDialog extends Component
{
    use ManagesExports;

    #[Locked]
    public string $type = 'album';

    #[Locked]
    public ?int $selectionId = null;

    /** @var list<int> */
    #[Locked]
    public array $trackIds = [];

    public bool $open = false;

    public string $profile = '';

    public string $layout = '';

    #[Locked]
    public ?string $exportId = null;

    public ?string $error = null;

    public function mount(string $type = 'album', ?int $id = null, array $trackIds = []): void
    {
        $this->type = $type;
        $this->selectionId = $id;
        $this->trackIds = array_map('intval', $trackIds);
        $this->profile = (string) config('export.default_profile');
    }

    public function toggle(): void
    {
        $this->guard();
        $this->open = !$this->open;
        $this->error = null;
    }

    public function start(): void
    {
        $this->guard();
        $this->error = null;

        try {
            $this->exportId = app(ExportService::class)->start($this->draft())->public_id;
        } catch (ExportRefused $e) {
            $this->error = $e->getMessage();
        }
    }

    public function another(): void
    {
        $this->guard();
        $this->exportId = null;
    }

    private function guard(): void
    {
        abort_unless(auth()->check() && $this->available(), 403);
    }

    private function available(): bool
    {
        return Profile::allows('library') && Profile::allows('file_storage');
    }

    private function draft(): Export
    {
        $profile = array_key_exists($this->profile, config('export.profiles')) ? $this->profile : config('export.default_profile');

        return app(ExportService::class)->draft($this->type, $this->selectionId, $this->trackIds, $profile, ['layout' => in_array($this->layout, ['tree', 'flat'], true) ? $this->layout : null]);
    }

    public function render(): View
    {
        $service = app(ExportService::class);
        $visible = auth()->check() && $this->available();
        $export = $visible && $this->exportId ? Export::query()->where('public_id', $this->exportId)->first() : null;

        return view('livewire.export-dialog', [
            'visible' => $visible,
            'profiles' => collect(config('export.profiles'))->map(fn ($p) => $p['label'])->all(),
            'estimate' => $visible && $this->open && !$export ? $service->estimate($this->draft()) : null,
            'export' => $export ? $service->resource($export) : null,
        ]);
    }
}
