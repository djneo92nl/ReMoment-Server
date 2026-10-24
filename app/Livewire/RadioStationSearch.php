<?php

namespace App\Livewire;

use App\Domain\Radio\RadioBrowser;
use App\Domain\Radio\RadioSuggestions;
use App\Models\RadioStation;
use App\Models\RadioStationMeta;
use Livewire\Component;

/** Search radio-browser.info on the add-station page and add a result with one click (name, stream URL, logo, genre). */
class RadioStationSearch extends Component
{
    public string $query = '';

    /** @var list<array<string, mixed>> the results of the last search */
    public array $results = [];

    public ?string $searched = null;

    /** @var array<string, int> stream URL → id of the station it was added as, in this session of the page */
    public array $added = [];

    /** @var list<string> genre tags offered as filters */
    public array $tags = [];

    public string $mode = 'trending';

    public ?string $tag = null;

    public function mount(): void
    {
        $this->tags = app(RadioBrowser::class)->tags();
    }

    public function search(): void
    {
        $this->searched = trim($this->query);
        $this->results = app(RadioBrowser::class)->search($this->searched);
    }

    /** Discover instead of search: trending, most voted or most played, optionally within a genre. */
    public function discover(string $mode = 'trending', ?string $tag = null): void
    {
        $this->mode = array_key_exists($mode, RadioBrowser::MODES) ? $mode : 'trending';
        $this->tag = $tag ?: null;
        $this->searched = null;
        $this->results = app(RadioBrowser::class)->browse($this->mode, $this->tag);
        $this->discovered = true;
    }

    public bool $discovered = false;

    /** "For you": trending stations in the genres of the favourite stations. */
    public function suggest(): void
    {
        $this->searched = null;
        $this->tag = null;
        $this->results = app(RadioSuggestions::class)->similarToFavourites();
        $this->discovered = true;
    }

    public function add(int $index): void
    {
        $result = $this->results[$index] ?? null;

        if ($result === null) {
            return;
        }

        $existing = RadioStationMeta::where('key', 'url')->where('value', $result['url'])->first()?->station;

        $station = $existing ?? RadioStation::create([
            'name' => $result['name'],
            'image_url' => $result['image_url'],
            'genre' => $result['genre'],
        ]);

        if ($existing === null) {
            $station->setMeta('url', $result['url']);

            if (!empty($result['uuid'])) {
                $station->setMeta('radio_browser_uuid', $result['uuid']);
            }
        }

        $this->added[$result['url']] = $station->id;
    }

    public function render()
    {
        return view('livewire.radio-station-search');
    }
}
