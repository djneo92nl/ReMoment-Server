# Party page: a guest queue

A device can have a **party link**. Behind it is a page where guests search the library and add tracks to that device's queue. There is nothing else on it: no skip, no volume, no removing, no other device. It fits the auth model as it is: no accounts, a guest is anyone who has the link.

## Switching it on

On `/devices/{id}/settings` the admin sees a **Party** card (Livewire `DevicePartySettings`) for a device with the `queue_add` capability, when the profile has a library. It switches the party on, sets how many tracks a guest may add per number of minutes (default 3 per 10), and shows the link with its QR code. "New link" replaces the token, so everyone with the old link is shut out; switching the party off does the same until it is switched on again (the link then is the same one).

State is one JSON value in the device meta key `party`: `{ enabled, token, limit, window_minutes }` (`App\Domain\Party\Party`). There is no table and no REST API for it yet.

## The page

`GET /party/{token}` (`PartyController`, view `party.blade.php`) is a page of its own, without the app layout: no sidebar, global search or player bar. It answers 404 for an unknown or old token or a party that is off, and is gated with the library (`party/*` in `config/profile.php`).

The Livewire component `PartyQueue` shows:

- a search field: tracks whose name or artist matches (MariaDB `LIKE`, 20 results), from the leading library source, and **only tracks this device can play** (`LibraryPlayback::playableTracks`);
- now playing and the next ten tracks, read-only, refreshed every 10 seconds (`wire:poll`).

**Add** puts the track at the end of the queue (`QueueAddInterface::addToQueue`, through `SpotifyRouting::driverFor`, then the `QueueUpdated` ping on MQTT). When the device is not playing, the first track starts the music (`LibraryPlayback::playTrack`, the track alone).

## Limits

- **Per visitor:** a visitor is a random cookie (`remoment_party_visitor`, one day), set by the page. `limit` adds per `window_minutes`, counted with Laravel's `RateLimiter`. The message says in how many minutes the next add is allowed.
- **Per address:** three times that limit per IP address, so clearing the cookie does not lift the limit.
- **No repeats:** a track that was added in the last 30 minutes is refused.
- The token and the visitor id are locked Livewire properties; every action looks the party up again, so a party that was switched off stops working on pages that are still open.

## Not built, not verified

- No REST API or MCP tool for the party; no voting; no approval step by the host.
- A guest's phone must reach the server's address in the link (the same network, or whatever the install exposes).
- Tried with the test drivers only, not on real speakers; per-brand queue behaviour is that of `queue_add` (`docs/api/queue.md`).
