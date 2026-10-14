<?php

namespace App\Livewire;

use App\Domain\Library\Enrichment;
use App\Domain\Storage\LibraryUploads;
use App\Models\Media\Track;
use Livewire\Component;

/**
 * Drop zone on /settings/storage: audio files dragged into the browser go to the inbox (the bytes travel
 * through LibraryUploadController, with progress in the page) and are then parsed, copied into the library
 * and enriched by ProcessLibraryUpload. This component shows the table with each file's step.
 */
class LibraryUploader extends Component
{
    /** After this long an enrichment that is still missing sources is shown as done: those services had nothing, or are rate limited. */
    private const ENRICH_WAIT_MINUTES = 15;

    public function clear(): void
    {
        LibraryUploads::clearFinished();
    }

    /** The browser lost the connection while sending the bytes. */
    public function uploadFailed(string $id, string $message = 'The upload was interrupted.'): void
    {
        if ((LibraryUploads::find($id)['state'] ?? null) === LibraryUploads::UPLOADING) {
            LibraryUploads::fail($id, $message);
        }
    }

    public function render()
    {
        $rows = array_map($this->resolve(...), LibraryUploads::all());

        return view('livewire.library-uploader', [
            'rows' => $rows,
            'polling' => collect($rows)->contains(fn ($row) => in_array($row['state'], LibraryUploads::ACTIVE, true)),
            'finished' => collect($rows)->contains(fn ($row) => !in_array($row['state'], LibraryUploads::ACTIVE, true)),
        ]);
    }

    /** `enriching` becomes `done` once every source the job queued is fetched (or after a while). */
    private function resolve(array $row): array
    {
        if ($row['state'] !== LibraryUploads::ENRICHING || !isset($row['queued_at'])) {
            return $row;
        }

        $track = isset($row['track_id']) ? Track::with('artist')->find($row['track_id']) : null;
        $pending = $track ? array_values(array_intersect($row['sources'] ?? [], Enrichment::pendingSources($track))) : [];

        if ($pending === []) {
            $row['state'] = LibraryUploads::DONE;
        } elseif (now()->parse($row['queued_at'])->lt(now()->subMinutes(self::ENRICH_WAIT_MINUTES))) {
            $row['state'] = LibraryUploads::DONE;
            $row['message'] = 'No answer from: '.implode(', ', $pending).'.';
        } else {
            $row['message'] = 'Waiting for: '.implode(', ', $pending).'.';
        }

        return $row;
    }
}
