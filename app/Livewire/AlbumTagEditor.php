<?php

namespace App\Livewire;

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

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(Album $album): void
    {
        $this->albumId = $album->id;
        $this->load();
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
        return view('livewire.album-tag-editor', ['albumName' => $this->album()->name]);
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
