<?php

namespace App\Livewire;

use App\Domain\Library\PlaylistEditor;
use App\Domain\Library\SmartPlaylist\SmartFields;
use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use App\Domain\Library\TrackBrowse;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /library/tracks: every track of the scope, filtered with the same rules a smart playlist has
 * (field / operator / value rows), so the filter set can be saved as a smart playlist.
 */
class TrackBrowser extends Component
{
    use WithPagination;

    public string $scope = 'all';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public bool $fav = false;

    #[Url]
    public string $sort = 'recently_added';

    #[Url]
    public string $match = 'all';

    /** @var list<array{field: string, operator: string, value: string|int|null}> */
    #[Url]
    public array $rules = [];

    public bool $showFilters = false;

    public string $saveName = '';

    public string $saveSort = 'random';

    public int $saveLimit = SmartPlaylistBuilder::DEFAULT_LIMIT;

    public function mount(string $scope): void
    {
        $this->scope = $scope;
        $this->showFilters = $this->rules !== [];
    }

    public function addRule(): void
    {
        $this->showFilters = true;
        $field = array_key_first(SmartFields::all());

        $this->rules[] = ['field' => $field, 'operator' => array_key_first(SmartFields::find($field)->operators), 'value' => ''];
    }

    public function removeRule(int $index): void
    {
        unset($this->rules[$index]);
        $this->rules = array_values($this->rules);
        $this->resetPage();
    }

    public function clearRules(): void
    {
        $this->rules = [];
        $this->resetPage();
    }

    /** A new field has other operators and values: start the row over. */
    public function updatedRules(mixed $value, string $key): void
    {
        [$index, $property] = array_pad(explode('.', $key, 2), 2, null);

        if ($property === 'field' && ($field = SmartFields::find($value)) && isset($this->rules[$index])) {
            $this->rules[$index]['operator'] = array_key_first($field->operators);
            $this->rules[$index]['value'] = '';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFav(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedMatch(): void
    {
        $this->resetPage();
    }

    /** Saves the current rules as a new smart playlist (the search text and favourites switch are not rules). */
    public function saveAsPlaylist(PlaylistEditor $editor)
    {
        $this->validate([
            'saveName' => ['required', 'string', 'max:255'],
            'saveLimit' => ['required', 'integer', 'min:1', 'max:'.SmartPlaylistBuilder::MAX_LIMIT],
        ]);

        $playlist = $editor->create(trim($this->saveName), smart: true);
        $editor->setRules($playlist, $this->definition($this->saveSort, $this->saveLimit));

        session()->flash('success', 'Smart playlist "'.$playlist->name.'" saved.');

        return $this->redirectRoute('playlists.show', $playlist);
    }

    private function definition(?string $sort = null, ?int $limit = null): array
    {
        return ['match' => $this->match, 'rules' => $this->rules, 'sort' => $sort ?? 'random', 'limit' => $limit ?? SmartPlaylistBuilder::DEFAULT_LIMIT];
    }

    public function render()
    {
        $sort = isset(SmartPlaylistBuilder::SORTS[$this->sort]) && $this->sort !== 'random' ? $this->sort : 'recently_added';
        $definition = $this->definition();
        $normalized = SmartPlaylistBuilder::normalize($definition);

        $tracks = SmartPlaylistBuilder::order(TrackBrowse::query($definition, $this->scope, $this->search, $this->fav), $sort)
            ->with(['artist', 'album'])
            ->paginate(100);

        return view('livewire.track-browser', [
            'tracks' => $tracks,
            'activeSort' => $sort,
            'fields' => SmartFields::definitions(),
            'sorts' => array_diff_key(SmartPlaylistBuilder::SORTS, ['random' => true]),
            'saveSorts' => SmartPlaylistBuilder::SORTS,
            'normalized' => $normalized,
            'incomplete' => count($this->rules) - count($normalized['rules']),
            'maxRules' => SmartPlaylistBuilder::MAX_RULES,
            'maxLimit' => SmartPlaylistBuilder::MAX_LIMIT,
            'pinned' => app(\App\Support\SelectedDevice::class)->device(),
        ]);
    }
}
