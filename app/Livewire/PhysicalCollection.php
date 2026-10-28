<?php

namespace App\Livewire;

use App\Domain\Catalogue\PhysicalItems;
use App\Models\Media\PhysicalItem;
use Livewire\Component;

/** The physical collection (CDs, records, tapes): add by barcode or by hand, filter, mark ripped, remove (docs/api/physical.md). */
class PhysicalCollection extends Component
{
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

        return view('livewire.physical-collection', ['items' => $items, 'total' => PhysicalItem::count(), 'media' => PhysicalItem::MEDIA]);
    }
}
