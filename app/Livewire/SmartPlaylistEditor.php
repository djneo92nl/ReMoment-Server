<?php

namespace App\Livewire;

use App\Domain\Library\SmartPlaylist\SmartFields;
use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use App\Models\Media\Playlist;
use Livewire\Component;

/** Rule editor of a smart playlist: rows of field / operator / value, with a live preview of what they select. */
class SmartPlaylistEditor extends Component
{
    public Playlist $playlist;

    public string $name = '';

    public string $match = 'all';

    /** @var list<array{field: string, operator: string, value: string|int|null}> */
    public array $rules = [];

    public string $sort = 'random';

    public int $limit = SmartPlaylistBuilder::DEFAULT_LIMIT;

    public function mount(Playlist $playlist): void
    {
        $this->playlist = $playlist;
        $this->name = $playlist->name;

        $definition = SmartPlaylistBuilder::normalize($playlist->rules);
        $this->match = $definition['match'];
        $this->rules = $definition['rules'];
        $this->sort = $definition['sort'];
        $this->limit = $definition['limit'];
    }

    public function addRule(): void
    {
        $field = array_key_first(SmartFields::all());

        $this->rules[] = ['field' => $field, 'operator' => array_key_first(SmartFields::find($field)->operators), 'value' => ''];
    }

    public function removeRule(int $index): void
    {
        unset($this->rules[$index]);
        $this->rules = array_values($this->rules);
    }

    /** A new field has other operators and values: start the row over. */
    public function updatedRules(mixed $value, string $key): void
    {
        [$index, $property] = array_pad(explode('.', $key, 2), 2, null);

        if ($property === 'field' && ($field = SmartFields::find($value)) && isset($this->rules[$index])) {
            $this->rules[$index]['operator'] = array_key_first($field->operators);
            $this->rules[$index]['value'] = '';
        }
    }

    public function save(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']]);

        $definition = SmartPlaylistBuilder::normalize($this->definition());

        $this->playlist->update(['name' => trim($this->name), 'rules' => $definition]);
        SmartPlaylistBuilder::refresh($this->playlist);

        session()->flash('success', 'Smart playlist saved.');

        $this->redirectRoute('playlists.show', $this->playlist);
    }

    private function definition(): array
    {
        return ['match' => $this->match, 'rules' => $this->rules, 'sort' => $this->sort, 'limit' => $this->limit];
    }

    public function render()
    {
        $definition = $this->definition();

        return view('livewire.smart-playlist-editor', [
            'fields' => SmartFields::definitions(),
            'sorts' => SmartPlaylistBuilder::SORTS,
            'matches' => SmartPlaylistBuilder::count($definition),
            'preview' => SmartPlaylistBuilder::preview($definition),
            'incomplete' => count($this->rules) - count(SmartPlaylistBuilder::normalize($definition)['rules']),
        ]);
    }
}
