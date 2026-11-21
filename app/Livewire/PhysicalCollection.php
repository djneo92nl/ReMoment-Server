<?php

namespace App\Livewire;

use App\Domain\Catalogue\PhysicalImport;
use App\Domain\Catalogue\PhysicalItems;
use App\Models\Media\PhysicalItem;
use Livewire\Component;
use Livewire\WithFileUploads;

/** The physical collection (CDs, records, tapes): add by barcode or by hand, filter, mark ripped, remove (docs/api/physical.md). */
class PhysicalCollection extends Component
{
    use WithFileUploads;

    public string $medium = '';

    public string $search = '';

    public string $ripped = ''; // '' | '1' | '0'

    public string $barcode = '';

    /** @var list<array<string, mixed>> releases MusicBrainz knows for the barcode */
    public array $candidates = [];

    public ?string $message = null;

    /** The add form (a chosen release fills it; typing it by hand works too). */
    public string $artist = '';

    public string $title = '';

    public string $year = '';

    public string $newMedium = 'cd';

    public string $location = '';

    public string $catalogueNumber = '';

    public string $releaseGroupMbid = '';

    public string $releaseMbid = '';

    /** CSV import: the file or pasted text, the medium for rows without one, and the preview that is waiting to be confirmed. */
    public $csvFile = null;

    public string $csvText = '';

    public string $importMedium = '';

    public ?string $importToken = null;

    public string $importFormat = '';

    /** @var array<string, int> */
    public array $importSummary = [];

    /** @var list<string> columns of the file that were not used */
    public array $importUnmapped = [];

    public string $importFilter = '';

    public ?string $importError = null;

    public function previewImport(): void
    {
        $this->importError = null;
        $this->importToken = null;
        $this->validate([
            'csvFile' => ['nullable', 'file', 'max:'.(int) (PhysicalImport::MAX_BYTES / 1024)],
            'csvText' => ['nullable', 'string'],
            'importMedium' => ['nullable', 'in:'.implode(',', PhysicalItem::MEDIA)],
        ]);

        $text = $this->csvFile ? (string) file_get_contents($this->csvFile->getRealPath()) : $this->csvText;
        if (trim($text) === '') {
            $this->importError = 'Choose a CSV file or paste its text first.';

            return;
        }

        try {
            $preview = PhysicalImport::preview($text, $this->importMedium ?: null, [], 0);
        } catch (\InvalidArgumentException $e) {
            $this->importError = $e->getMessage();

            return;
        }

        $this->importToken = $preview['token'];
        $this->importFormat = $preview['format'];
        $this->importSummary = $preview['summary'];
        $this->importUnmapped = $preview['unmapped'];
        $this->importFilter = '';
    }

    public function confirmImport(): void
    {
        $result = $this->importToken ? PhysicalImport::confirm($this->importToken) : null;
        if ($result === null) {
            $this->importError = 'This preview has expired. Choose the file again.';
            $this->cancelImport(false);

            return;
        }

        $this->message = "Imported {$result['added']} ".($result['added'] === 1 ? 'row' : 'rows')
            .($result['queued_lookups'] ? ", {$result['queued_lookups']} barcodes are looked up on MusicBrainz in the background (about one every ".PhysicalImport::LOOKUP_SPACING.' seconds)' : '')
            .". Skipped {$result['skipped_duplicates']} duplicates and {$result['skipped_invalid']} invalid rows.";
        $this->cancelImport(false);
    }

    public function cancelImport(bool $clearMessage = true): void
    {
        $this->reset('csvFile', 'csvText', 'importToken', 'importSummary', 'importUnmapped', 'importFilter', 'importFormat');
        if ($clearMessage) {
            $this->importError = null;
        }
    }

    public function lookup(): void
    {
        $this->message = null;
        $this->candidates = [];
        $this->validate(['barcode' => ['required', 'regex:/^[0-9 -]{6,20}$/']], ['barcode.regex' => 'A barcode is 6 to 20 digits.']);

        try {
            $this->candidates = PhysicalItems::lookupBarcode($this->barcode);
        } catch (\Throwable) {
            $this->message = 'MusicBrainz could not be reached. Fill the form in by hand, or try again.';

            return;
        }

        $this->message = $this->candidates === [] ? 'MusicBrainz knows no release with this barcode. Fill the form in by hand.' : null;
    }

    public function choose(int $index): void
    {
        $c = $this->candidates[$index] ?? null;
        if ($c === null) {
            return;
        }

        $this->artist = $c['artist'];
        $this->title = $c['title'];
        $this->year = (string) ($c['year'] ?? '');
        $this->newMedium = $c['medium'];
        $this->catalogueNumber = (string) ($c['catalogue_number'] ?? '');
        $this->releaseGroupMbid = (string) ($c['release_group_mbid'] ?? '');
        $this->releaseMbid = (string) $c['release_mbid'];
        $this->candidates = [];
    }

    public function save(): void
    {
        $data = $this->validate([
            'artist' => ['required', 'string', 'max:255'], 'title' => ['required', 'string', 'max:255'],
            'newMedium' => ['required', 'in:'.implode(',', PhysicalItem::MEDIA)], 'year' => ['nullable', 'integer', 'min:1000', 'max:2200'],
            'location' => ['nullable', 'string', 'max:255'], 'catalogueNumber' => ['nullable', 'string', 'max:64'],
        ]);

        PhysicalItems::add([
            'artist' => $data['artist'], 'title' => $data['title'], 'medium' => $data['newMedium'], 'year' => $data['year'] !== '' ? (int) $data['year'] : null,
            'location' => $data['location'] ?: null, 'catalogue_number' => $data['catalogueNumber'] ?: null,
            'barcode' => preg_replace('/\D/', '', $this->barcode) ?: null,
            'release_group_mbid' => $this->releaseGroupMbid ?: null, 'release_mbid' => $this->releaseMbid ?: null,
        ]);

        $this->message = "Added {$data['title']} by {$data['artist']}.";
        $this->reset('artist', 'title', 'year', 'location', 'catalogueNumber', 'releaseGroupMbid', 'releaseMbid', 'barcode', 'candidates');
    }

    public function toggleRipped(int $id): void
    {
        if ($item = PhysicalItem::find($id)) {
            $item->update(['ripped_at' => $item->ripped_at ? null : now()]);
        }
    }

    public function remove(int $id): void
    {
        if ($item = PhysicalItem::find($id)) {
            PhysicalItems::remove($item);
        }
    }

    public function render()
    {
        $items = PhysicalItem::query()
            ->when($this->medium !== '', fn ($q) => $q->where('medium', $this->medium))
            ->when($this->ripped !== '', fn ($q) => $this->ripped === '1' ? $q->whereNotNull('ripped_at') : $q->whereNull('ripped_at'))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('artist', 'like', '%'.trim($this->search).'%')->orWhere('title', 'like', '%'.trim($this->search).'%')))
            ->orderBy('artist_key')->orderBy('title_key')->limit(300)->get();

        $importRows = [];
        if ($this->importToken !== null) {
            $rows = PhysicalImport::rows($this->importToken);
            if ($rows === null) {
                $this->importError = 'This preview has expired. Choose the file again.';
                $this->cancelImport(false);
            } else {
                $importRows = array_map([PhysicalImport::class, 'present'], array_slice(
                    array_values(array_filter($rows, fn ($r) => $this->importFilter === '' || $r['status'] === $this->importFilter)), 0, 100,
                ));
            }
        }

        return view('livewire.physical-collection', [
            'items' => $items, 'total' => PhysicalItem::count(), 'media' => PhysicalItem::MEDIA,
            'importRows' => $importRows, 'importStatuses' => PhysicalImport::STATUSES,
        ]);
    }
}
