# Radio API

Stations are household-wide (`radio_stations`, platform identifiers in `radio_station_meta`: `beoradio`, `tunein`, `url`). Playing and listing are per device (see CLAUDE.md "Radio"); this page covers the station-level parts.

## What a station plays now

```
GET /api/radio/{station_id}/now   → { "now": { "artist": "…"|null, "title": "…", "source": "icy" } | null }
```

Works with or without a device on the station. Asking marks the station as listened to for 90 s and starts one poll chain (`PollRadioTitle`, every 15 s), so poll this endpoint while a screen shows the station; the first answer can be `null`. `RadioStationItem.now` has the same object from the cache only (never a live fetch), so lists stay fast.

`source` is the provider that found the title (`App\Domain\Radio\NowPlayingProvider`: `icy` = the stream's ICY metadata; more, like NTS, are added in `NowPlayingProviders::PROVIDERS`). MCP: `radio` tool with `station_id` and `now: true`.
