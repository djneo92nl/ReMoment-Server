# Lyrics extras

Roadmap 12.8. What the server works out from lyrics it already stored (LRCLIB, [metadata-enrichment.md](../architecture/metadata-enrichment.md)): the **language** and an **explicit** guess, plus two filters. All local: no request, no key, no model.

## Language

`App\Domain\Lyrics\LyricsLanguage::detect()` returns an ISO 639-1 code or null.

- Non-Latin text is told by its letters: Cyrillic (`ru`, or `uk` with ї є і ґ), kana/Han (`ja` with any kana, else `zh`), Hangul `ko`, Arabic `ar`, Greek `el`, Hebrew `he`, Thai `th`, Devanagari `hi`. Arabic script is always `ar` (Persian and Urdu are not told apart), Cyrillic is `ru` unless Ukrainian letters show.
- Latin text is told by how many of its words are common words of each of `en nl de fr es it pt sv da no pl` (words several languages share are left out of the lists). It needs 12 words, at least 10 % of them known words of the winner, and a 1.35× lead over the runner-up; Danish and Norwegian share most words, so only the other languages must be left behind. Short, mixed or unclear text gives null (stored as empty so the track is not read again).

## Explicit

`LyricsExplicit::in()` looks for strong swear words (English, Dutch, German, French, Spanish, Italian, Portuguese) as whole words; mild words (damn, hell, ass) are not on the list. When a track has **no** explicit flag from its source (Spotify) and the lyrics hit, the track gets `explicit = 1` with source `lyrics`. A clean flag is never derived (a lyrics source can be incomplete), and a source's own flag always wins (a later run removes the guess). It is a guess, so it can miss and in rare cases hit wrongly.

## Where it runs

`EnrichTrackLyrics` runs `LyricsAnalysis::apply()` after it stores lyrics; `php artisan library:lyrics-analysis [--limit=2000] [--redo]` (daily 03:20, Standard and Full) does the lyrics stored before this existed. Track metadata: `lyrics_language` (source `lyrics`).

## API

- `GET /api/library/tracks?lyrics_language=nl&explicit=1` (open): `lyrics_language` is a two letter code, `explicit=1` flagged by the source or found in the lyrics, `explicit=0` no flag at all. They combine with each other, the sound filters, `count_only` and the sort; unlike the sound filters they need no analysis. MCP: `browse_library` kind `tracks` with the same parameters.
- Track details (`TrackDetails`): `lyrics_language`, and `explicit` now also true from the guess.
- `now_playing.lyrics.language` on `GET /api/devices/{id}`.
- Smart playlists: the field **Lyrics language** (`lyrics_language`, `is` / `is not`, the languages present in the library); `explicit` (already there) includes the guess.

## Web

The lyrics block under the player on the device page shows the language as a badge, a **Copy** button and a **Translate** link. Translation stays a link as decided: it opens Google Translate with the source language set and nothing is sent from the server or the page; paste what Copy took. No offline, keyless translation exists that fits the Pi floor, so none is built. The album page's lyrics panel (`resources/views/albums/show.blade.php`) does not show these yet.

## Not built

Synced-lyrics follow-along already exists on `/receiver` (roadmap 8.1 item 5). A lyrics source switch has nothing to switch between: LRCLIB is the only source.

## Unverified

The word lists were checked on short samples per language, not on a real library; expect `null` for songs with little text and mislabelled Danish/Norwegian. The word lists are constants in `LyricsLanguage::WORDS` and `LyricsExplicit::WORDS`.
