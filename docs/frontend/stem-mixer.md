# Stem mixer (`/mixer`)

Two decks of kept stems, mixed in the browser. The server separates and stores the stems (kind `stem_audio`, [../api/stems.md](../api/stems.md), [../architecture/stems-service.md](../architecture/stems-service.md)); everything audible happens in the page with the Web Audio API. Nothing is sent back: a mix is played, not saved or rendered. Full profile only (the `deep_analysis` feature gate covers `/mixer`), open to guests like the API behind it.

## Entry points

- Album page, a track with an own file: the `…` menu has **Mix stems…**, which opens `/mixer?a={track id}` (deck A).
- `/mixer?a={id}&b={id}` loads library track ids into the decks; `MixerController` resolves them (an unknown id leaves the deck empty).
- Nothing is in the sidebar on purpose: it is a tool you open from a track.

## What a deck does

A deck loads a track's four stems, or offers **Make stems** when none are kept (`POST /api/library/tracks/{id}/deep-analysis {kinds:["stem_audio"]}`, then a poll every 3 s until the kind is `done`; the estimate shown is the API's `stem_audio_seconds`). Per deck:

- Play/pause, a seek bar, a thin level bar.
- Per stem (vocals, drums, bass, other): a fader, **M**ute and **S**olo. While any stem is soloed only soloed, unmuted stems sound.
- **Tempo** slider (0.7 to 1.4 of the track's own tempo, shown in BPM when the track has one), **Sync** (matches the other deck's current tempo, in half or double time when that is closer, `syncRate`), reset.
- **Key** −/+ in semitones (the Camelot code shown follows, a semitone is seven steps round the wheel) without changing the tempo.
- **Nudge** ±20 ms, to line the beats up by ear.

Between the decks: an equal-power **crossfader**, a master volume and **Play or pause both** (starts both decks in the same instant). Below: a search box (`GET /api/library/search`) and, once deck A has a track, "Mixes well with …" (`GET /api/library/tracks/{id}/mixes-with`: tempo within 6 % or half/double, same, relative or adjacent key). Each row loads into deck A or B.

## How the audio works

`resources/js/mixer/engine.js` (no DOM), `component.js` (the Alpine component `mixer`, registered in `resources/js/app.js`), `math.js` (pure helpers). The engine chunk, and the SoundTouch worklet, are loaded only when a deck loads stems (dynamic `import()`), so other pages pay nothing.

```
4 x AudioBufferSourceNode (playbackRate = tempo) -> envelope -> stem gain -> sum -> SoundTouch worklet -> deck gain -> master
```

- The four sources of a deck start at the **same audio-clock time**, so the stems stay sample-aligned. Seek, nudge, pause and tempo changes re-anchor all four together; each start and stop has a 6 ms fade so it does not click.
- A source's `playbackRate` changes tempo **and** pitch. The SoundTouch worklet (`@soundtouchjs/audio-worklet`, MPL-2.0, one per deck, `playbackRate` mirrored) puts the pitch back; its `pitchSemitones` moves the key on purpose. The stems are summed before it, so there is one stretch per deck, not four. Stem gains act before it, which delays a fader move by its latency (a few tens of ms).
- Stems are fetched from the signed URLs of `GET /api/library/tracks/{id}/stems` and decoded to float32 `AudioBuffer`s. **Memory**: about 340 MB per deck for a 4-minute track (`4 stems x 4 min x 44.1 kHz x 2 channels x 4 bytes`), shown while loading; a long track or two decks can be too much for a phone. Unloading a deck (or leaving the page) releases them.
- Audio objects live in the component's closure, not in Alpine's reactive state (proxies break Web Audio nodes).

## Limits

- **No beat grid.** BPM and key come from the analysis; Sync matches tempo, the phase of the beats is aligned by ear with Nudge. A mixer that beat-matches by itself needs a beat grid (not planned for 1.0).
- MP3 stems carry the encoder delay in the LAME header; all four are encoded alike, so they stay together even where a decoder ignores it. Set `STEM_AUDIO_FORMAT=flac` when sample-exact alignment matters (and bandwidth and memory use allow).
- No recording or export of a mix, no more than two decks, no effects, no MIDI or keyboard control.
- Needs the Web Audio `AudioWorklet` (any current browser; Safari needs a user gesture to start audio, which pressing Play is).

## Tests

- `tests/Js/mixer-math.test.mjs`: `node --test tests/Js/mixer-math.test.mjs` (sync rate, crossfade, mute/solo, Camelot shift, memory estimate).
- `tests/Feature/MixerPageTest.php`: the page, the tracks handed to the decks, the profile gate; the album page menu in `TrackStemsUiTest`.
- Verified in a real browser (the in-app browser pane) with synthetic stems: load, play in real time, tempo 1.2 without a pitch change (a 440 Hz tone stays at 440 Hz), +12 semitones moves it to 880 Hz, mute and solo, seek, pause, nudge, end of track; and the page with mocked API answers (Make stems flow and polling, both decks playing, Sync, mute/solo buttons, the picker, 375 px wide in dark mode, no horizontal scroll). **Not verified:** real stems from the Demucs service, a long track's memory use, Safari and iOS, and the page against the real dev server (it returned 500 for unrelated reasons that day).
