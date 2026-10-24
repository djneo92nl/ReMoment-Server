<?php

namespace App\Livewire;

use App\Domain\Library\LibraryIdentity;
use App\Integrations\Radio\Nts\NtsClient;
use Livewire\Component;

/** NTS' show archive: shows (paged), a show's episodes, an episode's official tracklist with the tracks the library has. */
class NtsBrowser extends Component
{
    private const PAGE = 24;

    public int $page = 0;

    public ?string $show = null;

    public ?string $episode = null;

    public function openShow(string $alias): void
    {
        $this->show = $alias;
        $this->episode = null;
    }

    public function openEpisode(string $alias): void
    {
        $this->episode = $alias;
    }

    public function back(): void
    {
        $this->episode !== null ? $this->episode = null : $this->show = null;
    }

    public function next(): void
    {
        $this->page++;
    }

    public function previous(): void
    {
        $this->page = max(0, $this->page - 1);
    }

    public function render()
    {
        $nts = app(NtsClient::class);
        $data = ['page' => $this->page, 'perPage' => self::PAGE];

        if ($this->show === null) {
            $data += $nts->shows($this->page * self::PAGE, self::PAGE);
        } elseif ($this->episode === null) {
            $data['episodes'] = $nts->episodes($this->show);
        } else {
            $data['tracklist'] = collect($nts->tracklist($this->show, $this->episode))->map(fn (array $t) => $t + [
                'album_id' => $t['artist'] === null ? null : LibraryIdentity::lookupTrack($t['artist'], null, $t['title'], null, null, null)?->album_id,
            ])->all();
            $data['episodeInfo'] = collect($nts->episodes($this->show))->firstWhere('alias', $this->episode);
        }

        return view('livewire.nts-browser', $data);
    }
}
