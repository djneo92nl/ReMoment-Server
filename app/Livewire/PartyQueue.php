<?php

namespace App\Livewire;

use App\Domain\Artwork\LibraryItemArtwork;
use App\Domain\Device\DeviceCache;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Library\LibrarySources;
use App\Domain\Party\Party;
use App\Events\Device\QueueUpdated;
use App\Integrations\Contracts\QueueAddInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Models\Device;
use App\Models\Media\Track;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The party page (docs/frontend/party.md): a guest searches the library and adds tracks to one device's queue.
 * Nothing else: no skip, no volume, no removing. Every action looks the party up by its token again, so a
 * switched-off party or a replaced link stops working at once, and adds are limited per visitor.
 */
class PartyQueue extends Component
{
    /** How many times a visitor's limit one address may add: a phone that clears its cookie is still held back. */
    private const ADDRESS_FACTOR = 3;

    /** A track that was just added is not added again for this long. */
    private const REPEAT_MINUTES = 30;

    #[Locked]
    public string $token = '';

    #[Locked]
    public string $visitor = '';

    public string $search = '';

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(string $token, string $visitor): void
    {
        $this->token = $token;
        $this->visitor = $visitor;
        abort_unless(Party::deviceForToken($token), 404);
    }

    public function add(int $trackId): void
    {
        $this->notice = $this->problem = null;

        $device = Party::deviceForToken($this->token);
        if (!$device) {
            $this->problem = 'The party is over.';

            return;
        }

        $track = $this->playable($device)->whereKey($trackId)->first();
        if (!$track) {
            $this->problem = 'That track cannot be played here.';

            return;
        }

        $party = Party::for($device);
        $window = $party['window_minutes'] * 60;
        $keys = [
            "party:{$device->id}:visitor:".sha1($this->visitor) => $party['limit'],
            "party:{$device->id}:address:".sha1((string) request()->ip()) => $party['limit'] * self::ADDRESS_FACTOR,
        ];
        foreach ($keys as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $this->problem = 'You can add another track in '.max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' min.';

                return;
            }
        }

        if (!Cache::add("party:{$device->id}:added:{$track->id}", true, self::REPEAT_MINUTES * 60)) {
            $this->problem = 'That track was added a moment ago.';

            return;
        }

        try {
            if (in_array($device->state, [State::Playing, State::Paused], true)) {
                $driver = SpotifyRouting::driverFor($device, QueueAddInterface::class);
                if (!($driver instanceof QueueAddInterface)) {
                    throw new \RuntimeException('no queue');
                }
                $driver->addToQueue(collect([$track]));
            } else {
                // Nothing plays: the first request starts the music.
                app(LibraryPlayback::class)->playTrack($device, $track, withAlbum: false);
            }
        } catch (\Throwable) {
            Cache::forget("party:{$device->id}:added:{$track->id}");
            $this->problem = 'It could not be added. Try again in a moment.';

            return;
        }

        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, $window);
        }
        QueueUpdated::dispatch($device->id);

        $this->notice = "Added: {$track->name}";
        $this->search = '';
    }

    private function playable(Device $device)
    {
        return LibraryPlayback::playableTracks(
            LibrarySources::tracks(Track::query()->with(['artist', 'album']), LibrarySources::hiddenForRequest(null)),
            $device,
        )->whereNotNull('tracks.album_id');
    }

    private function results(Device $device): array
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.addcslashes($term, '\\%_').'%';

        return $this->playable($device)
            ->where(fn ($q) => $q->where('tracks.name', 'like', $like)
                ->orWhereHas('artist', fn ($a) => $a->where('name', 'like', $like)))
            ->orderBy('tracks.name')->limit(20)->get()
            ->map(fn (Track $track) => [
                'id' => $track->id,
                'name' => $track->name,
                'artist' => $track->artist?->name,
                'image' => $track->album?->images[0] ?? null,
            ])->all();
    }

    private function upNext(Device $device): array
    {
        if ($device->state === State::Unreachable) {
            return [];
        }

        try {
            $driver = SpotifyRouting::driverFor($device, QueueInterface::class);

            return $driver instanceof QueueInterface
                ? array_map(function ($item) {
                    $row = $item->toArray();

                    return ['name' => $row['name'], 'artist' => $row['artist'] ?? null, 'image' => LibraryItemArtwork::proxy($row['image'] ?? null, 120)];
                }, $driver->getUpNext(10))
                : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function render()
    {
        $device = Party::deviceForToken($this->token);
        $playing = $device && in_array($device->state, [State::Playing, State::Paused], true) ? DeviceCache::getNowPlaying($device->id) : null;

        return view('livewire.party-queue', [
            'device' => $device,
            'playing' => $playing?->track ? ['name' => $playing->track->name, 'artist' => $playing->track->artist?->name] : null,
            'upNext' => $device ? $this->upNext($device) : [],
            'results' => $device ? $this->results($device) : [],
        ]);
    }
}
