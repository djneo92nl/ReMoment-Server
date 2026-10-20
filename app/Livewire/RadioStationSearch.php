<?php

namespace App\Livewire;

use App\Domain\Radio\RadioBrowser;
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

    public function search(): void
    {
        $this->searched = trim($this->query);
        $this->results = app(RadioBrowser::class)->search($this->searched);
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
        }

        $this->added[$result['url']] = $station->id;
    }

    public function render()
    {
        return view('livewire.radio-station-search');
    }
}
