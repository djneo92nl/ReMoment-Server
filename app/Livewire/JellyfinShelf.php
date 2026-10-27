<?php

namespace App\Livewire;

use App\Domain\Device\DeviceCache;
use App\Integrations\Jellyfin\JellyfinClient;
use App\Integrations\Jellyfin\JellyfinShelf as Tiles;
use App\Models\Device;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * "Continue watching" and "Next up" from the Jellyfin server, on the page of a TV while its Jellyfin app is in
 * front and plays nothing. Tapping a tile starts it in the app on that TV. The lists are read from the server
 * after the page has rendered (wire:init) and kept for a minute; the card shows or hides itself as the TV's
 * now playing changes.
 */
class JellyfinShelf extends Component
{
    private const PACKAGE = 'org.jellyfin.androidtv';

    public Device $device;

    public bool $ready = false;

    public ?string $error = null;

    public function mount(Device $device): void
    {
        $this->device = $device;
    }

    public function load(): void
    {
        $this->ready = true;
    }

    public function play(string $itemId, int|string $ticks = 0): void
    {
        try {
            $client = new JellyfinClient;
            $session = $client->sessionIdFor((string) $this->device->ip_address);

            if ($session === null) {
                $this->error = 'The Jellyfin app on the TV is not connected to the server.';

                return;
            }

            $client->play($session, $itemId, (int) $ticks);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = 'Could not start it: '.$e->getMessage();
        }
    }

    /** True while this TV shows its Jellyfin app and nothing plays in it. */
    private function visible(): bool
    {
        $now = DeviceCache::getNowPlaying($this->device->id);

        return JellyfinClient::configured() && $now?->source?->connector === self::PACKAGE && $now->track === null;
    }

    /** @return array{resume: array, next: array}|null */
    private function lists(): ?array
    {
        return Cache::remember("jellyfin:shelf:{$this->device->id}", 60, function () {
            try {
                $client = new JellyfinClient;
                $user = $client->userId();

                return $user === null ? null : [
                    'resume' => Tiles::tiles($client->resume($user), JellyfinClient::baseUrl()),
                    'next' => Tiles::tiles($client->nextUp($user), JellyfinClient::baseUrl()),
                ];
            } catch (\Throwable) {
                return null;
            }
        });
    }

    public function render()
    {
        $visible = $this->visible();
        $lists = $visible && $this->ready ? $this->lists() : null;

        return view('livewire.jellyfin-shelf', ['visible' => $visible, 'lists' => $lists]);
    }
}
