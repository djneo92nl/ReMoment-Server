<?php

namespace App\Livewire\Developer;

use App\Domain\Storage\FileIndexer;
use App\Models\Media\Track;
use Illuminate\Support\Collection;

/** Search box over the own-file tracks and the chosen one, for the developer tools that play a track. */
trait PicksTrack
{
    public string $search = '';

    public ?int $trackId = null;

    public function choose(int $trackId): void
    {
        $this->trackId = $trackId;
    }

    private function track(): ?Track
    {
        return $this->trackId ? Track::with(['artist', 'album', 'trackFiles.file'])->find($this->trackId) : null;
    }

    /** @return Collection<int, Track> */
    private function foundTracks(): Collection
    {
        $term = trim($this->search);

        return strlen($term) < 2 ? collect() : Track::with('artist')
            ->whereHas('metadata', fn ($q) => $q->where('key', 'dlna_url')->where('source', FileIndexer::URL_SOURCE))
            ->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhereHas('artist', fn ($a) => $a->where('name', 'like', "%{$term}%")))
            ->orderBy('name')->limit(8)->get();
    }
}
