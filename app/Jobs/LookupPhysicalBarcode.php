<?php

namespace App\Jobs;

use App\Domain\Catalogue\PhysicalImport;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\PhysicalItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * One barcode of a CSV import looked up on MusicBrainz (docs/api/physical.md): ties an item that was added to its release,
 * or adds the item of a row that had only a barcode. Behind the shared `musicbrainz` rate limiter, one request.
 */
class LookupPhysicalBarcode implements ShouldQueue
{
    use EnrichesFromSource;

    /** @param  array<string, mixed>  $fields  the imported row as item fields */
    public function __construct(public readonly ?PhysicalItem $item, public readonly array $fields) {}

    public function middleware(): array
    {
        return [new RateLimited('musicbrainz')];
    }

    public function handle(): void
    {
        PhysicalImport::resolve($this->item, $this->fields);
    }

    /** MusicBrainz stayed unreachable for every retry: a barcode-only row is kept under a placeholder name instead of being lost. */
    public function failed(\Throwable $e): void
    {
        if ($this->item === null) {
            PhysicalImport::addPlaceholder($this->fields, 'MusicBrainz could not be reached.');
        }
    }
}
