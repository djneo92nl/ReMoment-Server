<?php

namespace App\Http\Controllers;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Library\LeadingSource;
use App\Domain\Library\LibraryHome;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibrarySources;
use App\Domain\Library\ListeningModes;
use App\Domain\Library\RandomAlbum;
use App\Http\Controllers\Concerns\FlashesLibraryPlayback;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use App\Support\SelectedDevice;
use Illuminate\Http\Request;

class LibraryController extends Controller
{
    use FlashesLibraryPlayback;

    public function index(Request $request)
    {
        $scope = LeadingSource::forRequest($request);

        return view('library.index', LibraryHome::build($scope, LeadingSource::hidden($scope)) + ['scope' => $scope]);
    }

    /** The hero's Shuffle: a random album of the scope, shuffled, on the player pinned in this browser. */
    public function shuffle(Request $request, LibraryPlayback $library)
    {
        $device = app(SelectedDevice::class)->device();
        if ($device === null) {
            return back()->with('error', 'Pick a player in the bar below first.');
        }

        $album = RandomAlbum::pick(LeadingSource::hidden(LeadingSource::forRequest($request)), $device);
        if ($album === null) {
            return back()->with('error', 'There are no albums in this library yet.');
        }

        return $this->flashPlayback(fn () => $library->playAlbum($device, $album, shuffle: true), $device, $album->name);
    }

    /** A listening mode on the player pinned in this browser. */
    public function playListeningMode(string $mode, LibraryPlayback $library)
    {
        $found = ListeningModes::find($mode);
        abort_if($found === null, 404);

        $device = app(SelectedDevice::class)->device();
        if ($device === null) {
            return back()->with('error', 'Pick a player in the bar below first.');
        }

        $tracks = ListeningModes::tracks($mode, $device)->with(['metadata', 'artist'])->get();
        if ($tracks->count() < ListeningModes::MIN_TRACKS) {
            return back()->with('error', "{$device->device_name} can't play enough tracks for {$found['name']}.");
        }

        return $this->flashPlayback(fn () => $library->playTracks($device, $tracks), $device, $found['name'].' mode');
    }

    /** Favourite albums and artists of the scope, most recently favourited first. */
    public function favorites(Request $request)
    {
        $scope = LeadingSource::forRequest($request);
        $hidden = LeadingSource::hidden($scope);

        $albums = LibrarySources::albums(Album::query()->whereNotNull('favorited_at'), $hidden)
            ->with('artist')->orderByDesc('favorited_at')->orderByDesc('id')->get();
        $artists = LibrarySources::artists(Artist::query()->whereNotNull('favorited_at'), $hidden)
            ->withCount(['albums' => fn ($q) => LibrarySources::albums($q, $hidden), 'plays'])
            ->with(['metadata' => fn ($q) => $q->whereIn('key', Artist::PHOTO_METADATA), 'albums' => fn ($q) => LibrarySources::albums($q, $hidden)->withCount('plays')->orderByDesc('plays_count')->orderByDesc('created_at')])
            ->orderByDesc('favorited_at')->get();

        $tracks = LibrarySources::tracks(Track::query()->whereNotNull('favorited_at'), $hidden)
            ->with(['artist', 'album'])->orderByDesc('favorited_at')->orderByDesc('id')->limit(100)->get();

        LibraryItemArtwork::preload([
            ...LibraryItemArtwork::urlsOfAlbums($albums),
            ...LibraryItemArtwork::urlsOfAlbums($artists->map->coverAlbum()),
        ]);

        return view('library.favorites', compact('albums', 'artists', 'tracks', 'scope'));
    }
}
