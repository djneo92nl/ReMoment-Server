<?php

namespace App\Livewire;

use App\Domain\Radio\RadioBrowser;
use Livewire\Component;

/**
 * On a station's edit page: search radio-browser.info for its stream URL and hand the pick to the form
 * (`radio-url-picked` on the window fills the Stream URL, and the logo and genre when empty); saving stays the form's.
 */
class RadioStreamFinder extends Component
{
    public string $query = '';

    /** @var list<array<string, mixed>> */
    public array $results = [];

    public ?string $searched = null;

    public ?string $picked = null;

    public function mount(string $name = ''): void
    {
        $this->query = $name;
    }

    public function search(): void
    {
        $this->searched = trim($this->query);
        $this->picked = null;
        $this->results = app(RadioBrowser::class)->search($this->searched);
    }

    public function pick(int $index): void
    {
        $result = $this->results[$index] ?? null;

        if ($result === null) {
            return;
        }

        $this->picked = $result['url'];
        $this->dispatch('radio-url-picked', url: $result['url'], image_url: $result['image_url'], genre: $result['genre']);
    }

    public function render()
    {
        return view('livewire.radio-stream-finder');
    }
}
