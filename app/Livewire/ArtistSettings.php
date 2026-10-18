<?php

namespace App\Livewire;

use App\Domain\Library\ArtistIdentity;
use App\Domain\Library\ArtistOverrides;
use App\Domain\Library\LibraryRemover;
use App\Domain\Library\LibrarySources;
use App\Domain\Storage\LibraryFiles;
use App\Models\File;
use App\Models\Media\Artist;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The settings of one artist, section by section: which photo and backdrop pictures the pages use. Values chosen
 * here are `manual` metadata (ArtistOverrides), which outrank what enrichment found. Admin only: the page sits
 * behind the login, and every action checks it again (Livewire's own endpoint skips the route middleware).
 */
class ArtistSettings extends Component
{
    use WithFileUploads;

    public Artist $artist;

    public string $imageUrl = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    /** @var array{bio: string, genres: string, country: string, type: string, tags: string} what an admin set by hand; blank = automatic */
    public array $details = ['bio' => '', 'genres' => '', 'country' => '', 'type' => '', 'tags' => ''];

    public string $defaultSource = '';

    public string $nameInput = '';

    public string $mergeSearch = '';

    public string $mbidInput = '';

    public string $spotifyInput = '';

    public string $lastfmInput = '';

    /** @var list<array{id: string, name: string, detail: string}>|null */
    public ?array $mbCandidates = null;

    /** @var list<array{id: string, name: string, image: ?string}>|null */
    public ?array $spotifyCandidates = null;

    /** @var array<string, string> section => error message */
    public array $problems = [];

    /** @var array<string, string> section => confirmation message */
    public array $notices = [];

    public function mount(Artist $artist): void
    {
        $this->artist = $artist;
        $this->nameInput = $artist->name;
        $this->defaultSource = (string) $artist->manualValue('default_source');
        $this->details = [
            'bio' => (string) $artist->manualValue('bio'),
            'genres' => implode(', ', (array) json_decode((string) $artist->manualValue('genres'), true)),
            'country' => (string) $artist->manualValue('country'),
            'type' => (string) $artist->manualValue('artist_type'),
            'tags' => implode(', ', (array) json_decode((string) $artist->manualValue('tags'), true)),
        ];
    }

    public function choosePhoto(string $url): void
    {
        $this->guard();
        ArtistOverrides::setPhoto($this->artist, $url);
        $this->done('photo', 'Photo changed.');
    }

    public function resetPhoto(): void
    {
        $this->guard();
        ArtistOverrides::setPhoto($this->artist, null);
        $this->done('photo', 'Back to the automatic photo.');
    }

    public function toggleHidden(string $url): void
    {
        $this->guard();
        ArtistOverrides::toggleHidden($this->artist, $url);
        $this->done('photo');
    }

    public function setAllHidden(bool $hidden): void
    {
        $this->guard();
        ArtistOverrides::setAllHidden($this->artist, $hidden);
        $this->done('photo', $hidden ? 'No backdrop pictures on the artist page.' : 'All backdrop pictures are shown again.');
    }

    public function addImage(): void
    {
        $this->guard();

        if (!ArtistOverrides::addImageUrl($this->artist, $this->imageUrl)) {
            $this->problems['photo'] = 'That is not an http(s) link to a picture.';

            return;
        }

        $this->imageUrl = '';
        $this->done('photo', 'Picture added.');
    }

    public function updatedUpload(): void
    {
        $this->guard();
        $this->validate(['upload' => ['image', 'max:15360']]);

        if (!ArtistOverrides::addUpload($this->artist, (string) file_get_contents($this->upload->getRealPath()))) {
            $this->problems['photo'] = 'That picture is too small (at least 100 px) or not a JPEG, PNG, GIF or WebP.';
        } else {
            $this->done('photo', 'Picture uploaded.');
        }

        $this->upload = null;
    }

    public function removeImage(string $url): void
    {
        $this->guard();
        ArtistOverrides::removeImage($this->artist, $url);
        $this->done('photo');
    }

    public function searchMusicBrainz(): void
    {
        $this->guard();
        $this->attempt('identity', fn () => $this->mbCandidates = ArtistIdentity::searchMusicBrainz($this->artist->name));
    }

    public function chooseMusicBrainz(string $input): void
    {
        $this->guard();

        if (($mbid = ArtistIdentity::parseMbid($input)) === null) {
            $this->problems['identity'] = 'That is not a MusicBrainz id or artist link.';

            return;
        }

        ArtistIdentity::setMusicBrainz($this->artist, $mbid);
        $this->mbidInput = '';
        $this->mbCandidates = null;
        $this->done('identity', 'MusicBrainz match changed. The details are being fetched again.');
    }

    public function searchSpotify(): void
    {
        $this->guard();
        $this->attempt('identity', fn () => $this->spotifyCandidates = ArtistIdentity::searchSpotify($this->artist->name));
    }

    public function chooseSpotify(string $input): void
    {
        $this->guard();

        if (($id = ArtistIdentity::parseSpotifyId($input)) === null) {
            $this->problems['identity'] = 'That is not a Spotify artist id or link.';

            return;
        }

        ArtistIdentity::setSpotify($this->artist, $id);
        $this->spotifyInput = '';
        $this->spotifyCandidates = null;
        $this->done('identity', 'Spotify match changed. The photo is being fetched again.');
    }

    public function saveLastfmName(): void
    {
        $this->guard();

        if (trim($this->lastfmInput) === '') {
            $this->problems['identity'] = 'Enter the name Last.fm uses.';

            return;
        }

        ArtistIdentity::setLastfmName($this->artist, $this->lastfmInput);
        $this->lastfmInput = '';
        $this->done('identity', 'Last.fm name changed. Its data is being fetched again.');
    }

    public function resetMapping(string $key): void
    {
        $this->guard();
        abort_unless(in_array($key, ['mbid', 'spotify_id', 'lastfm_name'], true), 422);

        ArtistIdentity::reset($this->artist, $key);
        $this->done('identity', 'Back to the automatic match.');
    }

    public function refetch(string $source = ''): void
    {
        $this->guard();
        abort_unless($source === '' || isset(ArtistIdentity::SOURCES[$source]), 422);

        $queued = ArtistIdentity::refetch($this->artist, $source === '' ? [] : [$source]);
        $this->done('identity', $queued > 0 ? 'Fetching again. This can take a few minutes.' : 'Nothing to fetch: there is no MusicBrainz match yet, and Last.fm is not set up.');
    }

    public function rename(): void
    {
        $this->guard();

        if (!ArtistIdentity::rename($this->artist, $this->nameInput)) {
            $this->problems['names'] = 'The name can not be empty.';

            return;
        }

        $this->done('names', 'Renamed.');
    }

    public function merge(int $otherId): void
    {
        $this->guard();

        $merged = ArtistIdentity::mergeIn($this->artist, $otherId);
        $this->mergeSearch = '';
        $this->done('names', $merged > 0 ? 'Merged. Their albums, tracks and plays are on this artist now.' : 'That artist was already gone.');
    }

    public function saveDetails(): void
    {
        $this->guard();
        $this->validate(['details.bio' => ['nullable', 'string', 'max:5000'], 'details.country' => ['nullable', 'string', 'max:100'], 'details.type' => ['nullable', 'string']]);

        ArtistOverrides::setDetails($this->artist, $this->details);
        $this->done('details', 'Saved. Empty fields use what the sources found.');
    }

    public function saveDefaultSource(): void
    {
        $this->guard();
        ArtistOverrides::setDefaultSource($this->artist, $this->defaultSource ?: null);
        $this->done('library', 'Saved.');
    }

    public function toggleFileHidden(int $id, LibraryFiles $files): void
    {
        $this->guard();
        $file = $this->ownFile($id);
        $files->setHidden($file, !$file->isHidden());
        $this->done('library', $file->isHidden() ? 'Hidden from the library.' : 'Shown in the library again.');
    }

    public function setAllFilesHidden(bool $hidden, LibraryFiles $files): void
    {
        $this->guard();
        $count = 0;

        foreach ($this->filesQuery()->get() as $file) {
            if ($file->isHidden() !== $hidden) {
                $files->setHidden($file, $hidden);
                $count++;
            }
        }

        $this->done('library', $count.' '.($count === 1 ? 'file' : 'files').($hidden ? ' hidden from' : ' shown in').' the library.');
    }

    public function rematchFile(int $id, LibraryFiles $files): void
    {
        $this->guard();
        $this->attempt('library', fn () => $files->rematch($this->ownFile($id)));
        $this->notices['library'] ??= 'The file is matched to its track again.';
    }

    public function verifyFile(int $id, LibraryFiles $files): void
    {
        $this->guard();
        $result = $files->verify($this->ownFile($id));

        $this->done('library', match ($result) {
            'ok' => 'The file matches its stored hash.',
            'missing' => 'The file is missing from its location.',
            default => 'The file changed on disk and was re-indexed.',
        });
    }

    public function deleteFile(int $id, LibraryFiles $files): void
    {
        $this->guard();
        $this->attempt('library', fn () => $files->deleteFile($this->ownFile($id)));

        if (!isset($this->problems['library'])) {
            $this->done('library', 'File deleted.');
        }
    }

    public function removeFromLibrary()
    {
        $this->guard();
        $name = $this->artist->name;

        if (LibraryRemover::artist($this->artist) === null) {
            $this->problems['library'] = "\"{$name}\" has tracks from Local files or a DLNA server and can't be removed here. Delete those files or hide them.";

            return null;
        }

        session()->flash('success', "Removed \"{$name}\" from the library.");

        return $this->redirectRoute('artists.index');
    }

    /** The artist's own files, only ever those: ids come from the browser. */
    private function ownFile(int $id): File
    {
        return $this->filesQuery()->whereKey($id)->firstOrFail();
    }

    private function filesQuery()
    {
        return File::query()->whereHas('tracks', fn ($q) => $q->where('tracks.artist_id', $this->artist->id));
    }

    /** @return list<array{id: string, name: string, albums: int, tracks: int}> */
    private function sourceSummary(): array
    {
        $rows = [];

        foreach (LibrarySources::LIBRARIES as $id => $library) {
            $tracks = LibrarySources::availableFrom($this->artist->tracks(), $id);
            $trackCount = (clone $tracks)->count();

            if ($trackCount > 0) {
                $rows[] = ['id' => $id, 'name' => $library['name'], 'albums' => (clone $tracks)->distinct()->count('tracks.album_id'), 'tracks' => $trackCount];
            }
        }

        return $rows;
    }

    /** Runs a lookup that talks to an external service; its failure is shown in the section, not thrown. */
    private function attempt(string $section, \Closure $lookup): void
    {
        $this->problems = [];

        try {
            $lookup();
        } catch (\Throwable $e) {
            $this->problems[$section] = 'The search failed: '.$e->getMessage();
        }
    }

    private function done(string $section, ?string $notice = null): void
    {
        $this->problems = [];
        $this->notices = $notice ? [$section => $notice] : [];
        $this->artist->unsetRelation('metadata');
    }

    private function guard(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function render()
    {
        $this->artist->unsetRelation('metadata');

        return view('livewire.artist-settings', [
            'candidates' => ArtistOverrides::photoCandidates($this->artist),
            'photo' => $this->artist->photoUrl(),
            'photoIsManual' => ArtistOverrides::isSet($this->artist, 'photo'),
            'wide' => $this->artist->wideImageUrls(),
            'hidden' => $this->artist->hiddenImages(),
            'mergeCandidates' => ArtistIdentity::searchLibrary($this->artist, $this->mergeSearch),
            'automaticDetails' => [
                'bio' => $this->details['bio'] === '' ? $this->artist->bio() : null,
                'genres' => $this->details['genres'] === '' ? implode(', ', $this->artist->genres()) : null,
                'country' => $this->details['country'] === '' ? $this->artist->country() : null,
                'type' => $this->details['type'] === '' ? $this->artist->metaValue('artist_type') : null,
                'tags' => $this->details['tags'] === '' ? implode(', ', $this->artist->metaJson('tags')) : null,
            ],
            'sources' => $this->sourceSummary(),
            'files' => $this->filesQuery()->with(['location', 'tracks' => fn ($q) => $q->select('tracks.id', 'tracks.name')])->orderBy('path')->limit(200)->get(),
            'fileCount' => $this->filesQuery()->count(),
            'removable' => !LibraryRemover::ownedElsewhere($this->artist->tracks()),
            'mappings' => ArtistIdentity::mappings($this->artist),
            'spotifyConnected' => app(\App\Domain\Library\SpotifyCatalog::class)->available(),
        ]);
    }
}
