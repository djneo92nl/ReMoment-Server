<?php

namespace App\Livewire;

use App\Domain\Profile\Profile;
use App\Domain\Ripper\RipException;
use App\Domain\Ripper\Rips;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The "Rip this disc" card of the physical collection page: for every ReMoment Node with the ripper role, check the disc
 * in its drive, choose the MusicBrainz release, follow the rip. Everything goes through `App\Domain\Ripper\Rips`, the
 * implementation of the REST API (docs/api/rips.md); starting and cancelling need the admin login. Hidden without a
 * ripper node or in a profile without `rip_upload`.
 */
class RipDisc extends Component
{
    public ?string $error = null;

    /** The MusicBrainz release id typed in when the disc is not known there, per node. @var array<int, string> */
    public array $manual = [];

    public function check(int $nodeId): void
    {
        $this->begin($nodeId, null);
    }

    public function rip(int $nodeId, string $release): void
    {
        $this->begin($nodeId, $release);
    }

    public function ripManual(int $nodeId): void
    {
        $this->begin($nodeId, trim($this->manual[$nodeId] ?? ''));
    }

    public function cancel(string $id): void
    {
        abort_unless(auth()->check(), 403);

        if ($job = Rips::findJob($id)) {
            Rips::cancel($job);
        }
    }

    private function begin(int $nodeId, ?string $release): void
    {
        abort_unless(auth()->check(), 403);
        $this->error = null;

        $node = Rips::find($nodeId);
        if ($node === null) {
            $this->error = 'That node has no ripper role switched on.';

            return;
        }
        if ($release !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $release)) {
            $this->error = 'That is not a MusicBrainz release id (a UUID).';

            return;
        }

        try {
            Rips::start($node, $release);
        } catch (RipException $e) {
            $this->error = $e->getMessage();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    public function render(): View
    {
        $available = Profile::allows('rip_upload');
        $nodes = $available ? Rips::nodes() : collect();

        $cards = $nodes->map(fn ($n) => ['node' => Rips::describeNode($n), 'job' => Rips::latest($n)])->all();
        $active = collect($cards)->contains(fn ($c) => in_array($c['job']['state'] ?? '', ['probing', 'looking_up', 'queued', 'ripping', 'uploading'], true));

        return view('livewire.rip-disc', ['cards' => $cards, 'active' => $active]);
    }
}
