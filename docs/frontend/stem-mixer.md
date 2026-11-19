# Stem mixer (`/mixer`)

How to use it, step by step: [mixer-guide.md](mixer-guide.md).

Two decks of kept stems, mixed in the browser. The server separates and stores the stems (kind `stem_audio`, [../api/stems.md](../api/stems.md), [../architecture/stems-service.md](../architecture/stems-service.md)); everything audible happens in the page with the Web Audio API. Nothing is sent back: a mix is played, not saved or rendered. Full profile only (the `deep_analysis` feature gate covers `/mixer`), open to guests like the API behind it.

## Entry points

- Album page, a track with an own file: the `…` menu has **Mix stems…**, which opens `/mixer?a={track id}` (deck A).
- `/mixer?a={id}&b={id}` loads library track ids into the decks; `MixerController` resolves them (an unknown id leaves the deck empty).
- The sidebar has a **Tools** entry ([tools-menu.md](tools-menu.md)) with **Mixer** under it (Full profile only); it opens `/mixer` with both decks empty and the picker.

## What a deck does

A deck loads a track's stems (four, or six with guitar and piano when the server runs `STEMS_MODEL=htdemucs_6s`: the faders, mute, solo and waveforms follow the stems the track has), or offers **Make stems** when none are kept (`POST /api/library/tracks/{id}/deep-analysis {kinds:["stem_audio"]}`, then a poll every 3 s until the kind is `done`; the estimate shown is the API's `stem_audio_seconds`). Per deck:

- Play/pause, a seek bar, a thin level bar.
- Per stem (vocals, drums, bass, other): a fader, **M**ute and **S**olo. While any stem is soloed only soloed, unmuted stems sound.
- **Tempo** slider (0.7 to 1.4 of the track's own tempo, shown in BPM when the track has one), **Sync** (matches the other deck's current tempo, in half or double time when that is closer, `syncRate`), reset.
- **Key** −/+ in semitones (the Camelot code shown follows, a semitone is seven steps round the wheel) without changing the tempo.
- **Nudge** ±20 ms, to line the beats up by ear, and **Beat** ‹‹ ›› to shift by a whole beat.
- **Beat grid**, **cues** and **loops**: see "Beat grid, sync, cues and loops" below.

Between the decks: an equal-power **crossfader**, a master volume and **Play or pause both** (starts both decks in the same instant). Below: a search box (`GET /api/library/search`) and, once deck A has a track, "Mixes well with …" (`GET /api/library/tracks/{id}/mixes-with`: tempo within 6 % or half/double, same, relative or adjacent key). Each row loads into deck A or B.

## How the audio works

`resources/js/mixer/engine.js` (no DOM), `component.js` (the Alpine component `mixer`, registered in `resources/js/app.js`), `math.js` (pure helpers). The engine chunk, and the SoundTouch worklet, are loaded only when a deck loads stems (dynamic `import()`), so other pages pay nothing.

```
4 x AudioBufferSourceNode (playbackRate = tempo, loop, loopStart, loopEnd) -> envelope -> stem gain -> sum -> SoundTouch worklet -> deck gain -> master
```

- The four sources of a deck start at the **same audio-clock time**, so the stems stay sample-aligned. Seek, nudge, pause and tempo changes re-anchor all four together; each start and stop has a 6 ms fade so it does not click.
- A source's `playbackRate` changes tempo **and** pitch. The SoundTouch worklet (`@soundtouchjs/audio-worklet`, MPL-2.0, one per deck, `playbackRate` mirrored) puts the pitch back; its `pitchSemitones` moves the key on purpose. The stems are summed before it, so there is one stretch per deck, not four. Stem gains act before it, which delays a fader move by its latency (a few tens of ms).
- Stems are fetched from the signed URLs of `GET /api/library/tracks/{id}/stems` and decoded to float32 `AudioBuffer`s. **Memory**: about 340 MB per deck for a 4-minute track (`4 stems x 4 min x 44.1 kHz x 2 channels x 4 bytes`), shown while loading; a long track or two decks can be too much for a phone. Unloading a deck (or leaving the page) releases them.
- Audio objects live in the component's closure, not in Alpine's reactive state (proxies break Web Audio nodes).

## Beat grid, sync, cues and loops (DJ plan D1 to D3)

Plan and decisions: [dj-mode.md](dj-mode.md). Files: `resources/js/mixer/beats.js` (grid, pure), `beats.worker.js` + `beats-client.js` (the Web Worker; the stems are sent in 1M-sample chunks so the worker never holds a whole stem; without `Worker` it runs on the page in chunks), `cues.js` (loop and cue arithmetic, pure), `engine.js` (scheduling, loops), `component.js` (state and persistence).

- **Grid** `{ bpm, offset, downbeat, lock, bar }`: found once per stems set after a deck loads, from the drums stem (onsets: level of the drums, its high-passed level at half weight, and the kick-and-bass band) and the bass stem (the bar's first beat has the most low level). Needs 12 s or more and a tempo that stays on the same spot in at least 75 % of the audible 8 s segments, else **no grid**: the deck shows "No steady beat, beat features are off" and Quantize, beat loops and phase sync stay off (cues, In/Out loops, tempo Sync and Beat shift still work). The result, or the absence of one, is cached in `localStorage` as `remoment.mixer.grid.v1.<track id>:<duration ms>:<stem bytes>` (the API has no file hash; separating again changes the key), together with the user's grid nudge and "Downbeat here". Thresholds are in `GRID` in `beats.js`.
- **Sync**: tempo from the grid BPMs (the analysed BPM without a grid), then, with both decks playing on a grid, a scheduled seek so the beats coincide (`phaseShift`: at most half a beat the shorter way, at `now + 60 ms` on the audio clock). Half and double time line up on every second or every beat.
- **Quantize** (per deck, default on, effective with a grid): a start while the other deck plays on a grid lands on that deck's next beat (`Deck.nextBeatTime`, `playAt`) from the nearest beat of this deck's own grid; cues snap to the nearest beat when set; a hot cue pressed while playing jumps at the deck's next beat (`seek(target, when)`: new sources start at `when`, old ones fade out, the clock switches over in `#settle`).
- **Cues**: the main cue (set / return / preview while held) and four hot cues (empty: set here; set: jump; × clears), shown as markers on the waveform. Stored per stems fingerprint in `localStorage` (`remoment.mixer.cues.v1.…`), so they are per browser, not household-wide.
- **Loops**: In / Out, beat loops 1/4, 1/2, 1, 2, 4, 8 (start on the nearest beat) and ½× / 2× on the active loop. The loop is on the four buffer sources (`loop`, `loopStart`, `loopEnd`, in track time), so it costs nothing and stays sample-aligned; the deck's clock wraps by the same arithmetic (`wrapLoop`). Not stored.

- **Transition card** (D4): preset (Blend, Vocals out / drums in, Drop), 8/16/32 bars, **Mix now**, **Cancel**; see [dj-mode.md](dj-mode.md) for what each preset does. Needs a grid on both decks and the outgoing deck playing. The crossfader slider and every stem fader cancel a running transition where it stands.

Page `localStorage` use is guarded: a private window or a full store just means nothing is remembered.

## Limits

- **The grid is found in the browser and tuned on synthetic click tracks only**; on real music it can pick the wrong octave, put the grid on the off-beat (disco hats), or the wrong beat as the bar start (use the grid nudge and "Downbeat here"). The plan for the rest (stem transitions, EQ and effects, keyboard, recording) is [dj-mode.md](dj-mode.md) (D4 and later not built, not planned for 1.0).
- MP3 stems carry the encoder delay in the LAME header; all four are encoded alike, so they stay together even where a decoder ignores it. Set `STEM_AUDIO_FORMAT=flac` when sample-exact alignment matters (and bandwidth and memory use allow).
- No recording or export of a mix, no more than two decks, no effects, no MIDI or keyboard control, cues are per browser.
- Needs the Web Audio `AudioWorklet` (any current browser; Safari needs a user gesture to start audio, which pressing Play is).

## Tests

- `tests/Js/mixer-beats.test.mjs` (`node --test tests/Js/mixer-beats.test.mjs`): synthetic click tracks with a known tempo and offset recovered within 5 ms to the end of the track, a slightly wrong analysed BPM refined, half/double resolved by the seed, the downbeat on each of the four beats, noise / wandering tempo / a tempo step / too short give no grid, snapping, grid shift and renumbering, `phaseShift` (equal phases after the shift for random decks, half time), stored grids and the fingerprint.
- `tests/Js/mixer-transitions.test.mjs`: bass exclusivity outside the swap window, crossfader and stem timelines of the three presets, kept levels, six stems, curve sampling, plan duration.
- `tests/Js/mixer-cues.test.mjs`: beat loops (nearest beat, clamping to the track), loop points, halve/double, `wrapLoop` over many laps, cue sanitising.
- `tests/Js/mixer-math.test.mjs`: `node --test tests/Js/mixer-math.test.mjs` (sync rate, crossfade, mute/solo, Camelot shift, memory estimate).
- `tests/Feature/MixerPageTest.php`: the page, the tracks handed to the decks, the profile gate; the album page menu in `TrackStemsUiTest`.
- Verified in a real browser (the in-app browser pane) with synthetic stems: load, play in real time, tempo 1.2 without a pitch change (a 440 Hz tone stays at 440 Hz), +12 semitones moves it to 880 Hz, mute and solo, seek, pause, nudge, end of track; and the page with mocked API answers (Make stems flow and polling, both decks playing, Sync, mute/solo buttons, the picker, 375 px wide in dark mode, no horizontal scroll). Beat features, verified the same way (in-app browser on plain http, synthetic 120 and 126 BPM stems, an `AnalyserNode` on the master, 1024-point FFT so about 23 ms resolution): the worker recovers 119.998 BPM and an offset 0.9 ms early; a one-beat loop plays one tone only for 3.6 s while the position stays in the loop; a quantized start of deck B lands within 0 to 23 ms of deck A's beat step; a scheduled jump switches at the beat and the clock continues from the target; Sync brings two decks that were 0.45 beat apart to a phase difference of 0.000; nudge 100 ms and Beat shift move exactly as asked; grid and cues come back from `localStorage` after reloading a deck; 375 px wide has no horizontal scroll. Transitions: a 2-bar Blend (the select offers 8/16/32) armed on the next bar line, the bass swapped at the bar line, the crossfader ended on the incoming deck with deck A's output at 0, and a fader grab froze the levels while the audio went on. **Not verified:** how any of it feels on real tracks (the grid has only seen synthetic clicks; a 4-minute stereo track takes about 0.1 s to analyse in Node, the browser worker was not timed on a real track), loops, scheduled jumps and quantized starts with the SoundTouch worklet on (https or localhost; the check ran on plain http without it), the extra memory of the worker's chunks, real stems from the Demucs service, a long track's memory use, Safari and iOS, and the page against the real dev server (it returned 500 for unrelated reasons that day).
