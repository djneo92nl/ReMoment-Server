<?php

namespace App\Livewire;

use App\Domain\Storage\AlbumSuggestions;
use App\Domain\Storage\TagEditor;
use App\Models\Media\Album;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Edits the tags, cover and names of every own file of an album in one go (workstream 8.4, docs/frontend/tag-editor.md):
 * album-level fields at the top (album, album artist, year, genre; a field the files disagree on stays empty with a note and is
 * only written when typed), one row per file (title, artist, track and disc number), an optional new cover that is embedded in
 * every file and becomes the album picture, and Undo for the last edit of the album. Admin only: it sits under /settings
 * behind the login, and every action checks it again. Domain: App\Domain\Storage\TagEditor.
 */
class AlbumTagEditor extends Component
{
    use WithFileUploads;

    public int $albumId;

    /** @var array<string, string> album, album_artist, year, genre as typed */
    public array $fields = [];

    /** @var array<string, string> the same as the files hold them ('' where they differ) */
    public array $originalFields = [];

    /** @var list<string> album-level fields the files disagree on */
    public array $mixed = [];

    /** @var array<int, array<string, mixed>> file id => path, title, artist, track_number, disc_number, editable, can_undo */
    public array $rows = [];

    /** @var array<int, array<string, string>> */
    public array $originalRows = [];

    public bool $organize = true;

    public $cover = null;

    public bool $canUndo = false;

    public bool $anyEditable = false;

    /** Hint for the lookup (what the album could be) and its answer; a picked cover is a Cover Art Archive URL fetched on save. */
    public string $cueArtist = '';

    public string $cueAlbum = '';

    public ?string $coverUrl = null;

    /** @var list<array<string, mixed>>|null releases found by the cover search (null: not searched yet) */
    public ?array $coverResults = null;

    public ?string $coverSearchError = null;

    /** @var array{releases: list<array<string, mixed>>, artist: ?array<string, mixed>, searched: bool, error: ?string} */
    public array $suggestions = ['releases' => [], 'artist' => null, 'searched' => false, 'error' => null];

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(Album $album): void
    {
        $this->albumId = $album->id;
        $this->cueArtist = (string) $album->artist?->name;
        $this->cueAlbum = $album->name;
        $this->load();
    }

    /** Looks up the artist's albums and matching releases online (started by the page after it rendered). */
    public function lookup(AlbumSuggestions $suggestions): void
    {
        abort_unless(auth()->check(), 403);
        try {
            $this->suggestions = $suggestions->lookup(trim($this->fields['album_artist'] ?? '') ?: $this->cueArtist, $this->cueAlbum) + ['searched' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->suggestions = ['releases' => [], 'artist' => null, 'searched' => true, 'error' => 'MusicBrainz did not answer: '.$e->getMessage()];
        }
    }

    /** Fills the form from a candidate (`releases` or `albums`); `what` is tags, cover or both. Nothing is written until save. */
    public function use(string $kind, int $index, string $what = 'both'): void
    {
        $candidate = $kind === 'albums' ? ($this->suggestions['artist']['albums'][$index] ?? null) : ($this->suggestions['releases'][$index] ?? null);
        if ($candidate === null) {
            return;
        }
        if ($what !== 'cover') {
            $this->fields['album'] = (string) $candidate['title'];
            if ($kind === 'releases' && $candidate['artist'] !== '') {
                $this->fields['album_artist'] = (string) $candidate['artist'];
            } elseif ($kind === 'albums') {
                $this->fields['album_artist'] = (string) $this->suggestions['artist']['name'];
            }
            if ($candidate['year']) {
                $this->fields['year'] = (string) $candidate['year'];
            }
        }
        if ($what !== 'tags') {
            $this->cover = null;
            $this->coverUrl = $candidate['cover'];
        }
    }

    /** Searches covers for what the form says now (album and album artist as typed, else the hints), so no reload is needed after an edit. */
    public function searchCovers(AlbumSuggestions $suggestions): void
    {
        abort_unless(auth()->check(), 403);
        $this->coverSearchError = null;
        $artist = trim($this->fields['album_artist'] ?? '') ?: trim($this->cueArtist);
        $album = trim($this->fields['album'] ?? '') ?: trim($this->cueAlbum);

        try {
            $this->coverResults = $suggestions->searchReleases($artist, $album);
        } catch (\Throwable $e) {
            $this->coverResults = [];
            $this->coverSearchError = 'The search failed: '.$e->getMessage();
        }
    }

    public function useCover(int $index): void
    {
        if ($url = $this->coverResults[$index]['cover'] ?? null) {
            $this->cover = null;
            $this->coverUrl = $url;
        }
    }

    public function clearCoverUrl(): void
    {
        $this->coverUrl = null;
    }

    public function updatedCover(): void
    {
        $this->validate(['cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:10240']]);
    }

    public function save(TagEditor $editor): void
    {
        abort_unless(auth()->check(), 403);
        $this->notice = $this->error = null;

        $tags = [];
        foreach ($this->fields as $field => $value) {
            if (trim($value) !== ($this->originalFields[$field] ?? '') && !(in_array($field, $this->mixed, true) && trim($value) === '')) {
                $tags[$field] = trim($value);
            }
        }

        $perFile = [];
        foreach ($this->rows as $id => $row) {
            $changed = array_diff_assoc(array_map('trim', array_intersect_key($row, $this->originalRows[$id] ?? [])), $this->originalRows[$id] ?? []);
            if ($changed !== []) {
                $perFile[$id] = $changed;
            }
        }

        try {
            $cover = $this->cover ? $editor->coverFrom((string) file_get_contents($this->cover->getRealPath())) : null;
            if ($cover === null && $this->coverUrl) {
                $bytes = app(AlbumSuggestions::class)->coverBytes($this->coverUrl);
                if ($bytes === null) {
                    throw new \RuntimeException('The Cover Art Archive has no picture for that release; pick another or upload one.');
                }
                $cover = $editor->coverFrom($bytes);
            }
            $result = $editor->editAlbum($this->album(), $tags, $perFile, $cover, $this->organize);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->finish($result, 'edited');
    }

    public function undo(TagEditor $editor): void
    {
        abort_unless(auth()->check(), 403);
        $this->notice = $this->error = null;

        try {
            $result = $editor->undoAlbum($this->album());
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->finish($result, 'undone');
    }

    public function render()
    {
        return view('livewire.album-tag-editor', ['albumName' => $this->album()->name, 'artworkReasons' => \App\Domain\Library\AlbumArtworkDiagnosis::reasons($this->album())]);
    }

    /** Reports what happened and reloads: the album may be another record now (a new name), so the page follows it. */
    private function finish(array $result, string $key): void
    {
        $count = count($result[$key]);
        $this->notice = ($key === 'edited' ? "{$count} ".($count === 1 ? 'file' : 'files').' rewritten.' : "{$count} ".($count === 1 ? 'edit' : 'edits').' undone.');
        if (!empty($result['skipped'])) {
            $this->notice .= ' '.count($result['skipped']).' skipped (inbox or a format that cannot be written).';
        }
        if (!empty($result['failed'])) {
            $this->error = count($result['failed']).' failed: '.collect($result['failed'])->map(fn ($f) => "file {$f['file_id']}: {$f['error']}")->implode('; ');
        }

        $this->cover = null;
        $this->coverUrl = null;
        $this->coverResults = null;
        $this->albumId = (int) $result['album_id'];
        $this->load();
    }

    private function album(): Album
    {
        return Album::findOrFail($this->albumId);
    }

    private function load(): void
    {
        $editor = app(TagEditor::class);
        $album = $this->album();
        $files = $editor->albumFiles($album);
        $states = $files->mapWithKeys(fn ($file) => [$file->id => [$file, $editor->read($file)]]);

        $this->rows = $this->originalRows = [];
        foreach ($states as $id => [$file, $state]) {
            $this->rows[$id] = ['path' => $file->path, 'editable' => $state['editable'], 'can_undo' => $state['can_undo'], 'has_cover' => $state['has_cover']]
                + array_intersect_key($state['tags'], array_flip(['title', 'artist', 'track_number', 'disc_number']));
            $this->originalRows[$id] = array_intersect_key($state['tags'], array_flip(['title', 'artist', 'track_number', 'disc_number']));
        }

        $this->fields = $this->originalFields = [];
        $this->mixed = [];
        foreach (['album', 'album_artist', 'year', 'genre'] as $field) {
            $values = $states->map(fn ($s) => $s[1]['tags'][$field])->unique()->values();
            $this->fields[$field] = $this->originalFields[$field] = $values->count() === 1 ? (string) $values[0] : '';
            if ($values->count() > 1) {
                $this->mixed[] = $field;
            }
        }

        $this->canUndo = $states->contains(fn ($s) => $s[1]['can_undo']);
        $this->anyEditable = $states->contains(fn ($s) => $s[1]['editable']);
    }
}
