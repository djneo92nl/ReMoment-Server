<?php

namespace App\Livewire\Concerns;

use App\Domain\Export\ExportService;
use App\Models\Export;

/** Cancel/remove of an export from the web; admin only, through the same service as the REST API. */
trait ManagesExports
{
    public function cancelExport(string $id): void
    {
        abort_unless(auth()->check(), 403);

        $export = Export::query()->where('public_id', $id)->first();
        if ($export) {
            app(ExportService::class)->cancel($export);
        }
        if (property_exists($this, 'exportId') && $this->exportId === $id) {
            $this->exportId = null;
        }
    }
}
