# Admin radio stations (`/api/admin/radio-stations`)

Managing the stations themselves, behind the admin token (`401 unauthenticated` without one): the actions of `/radio/create` and `/radio/{id}/edit`, through `App\Domain\Radio\RadioStations` (the web pages use it too). Reading and playing stays open: `GET /api/devices/{id}/radio`, `POST /api/devices/{id}/radio/{station}`, `PUT /api/radio/{station}/favorite`, `GET /api/radio/{station}/now` (see the REST reference). MCP: tool `radio_stations`.

```
GET    /api/admin/radio-stations?q=                → { data: [Station] }   every station by name (the NTS ones too)
POST   /api/admin/radio-stations                   { name, genre?, image_url?, identifiers? }   → 201 { data: Station }
GET    /api/admin/radio-stations/{id}              → { data: Station }
PUT    /api/admin/radio-stations/{id}              { name?, genre?, image_url?, identifiers? }  → { data: Station }
DELETE /api/admin/radio-stations/{id}              → { status: "ok" }
GET    /api/admin/radio-stations/search?q=         → { data: [Result] }    the stream finder
POST   /api/admin/radio-stations/directory         Result                  → 201 | 200 { data: Station }
```

`Station` is the item every client sees (`id`, `name`, `genre`, `favorite`, `artwork`, `now`; see the REST reference) plus `image_url` (the logo as stored) and `identifiers`, an object of platform → identifier, sorted by platform (`{}` when none).

## Identifiers

A station plays on a device through the identifier of that device's radio platform: `url` (a plain MP3/AAC stream, any device that plays a stream), `beoradio` (ASE, Mozart), `tunein` (Sonos). Others (`radio_browser_uuid`, `nts_*`) are kept by the server. On **create** empty values are skipped; on **update** only the platforms named are touched, and **an empty value removes that identifier**. Names are lowercase letters, digits and underscores; `identifiers.url` must be a URL. `image_url` is a URL or `null`.

## Stream finder

`search?q=` asks radio-browser.info (needs 2 or more characters; an unreachable directory gives an empty list) and returns plain streams only (playlists and HLS are dropped): `{ name, url, image_url, genre, codec, bitrate, country, uuid, homepage }`. `POST …/directory` adds such a result (`name` and `url` required, `uuid` optional): **`201`** with a new station (`url` and `radio_browser_uuid` identifiers), **`200`** with the station that already has this stream URL. Nothing is added twice.
