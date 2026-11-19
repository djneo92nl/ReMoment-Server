# Mini DJ mode (plan; D1 to D4 built)

Plan for turning the stem mixer ([stem-mixer.md](stem-mixer.md)) into a small DJ app: two decks, beat-aware mixing, cues and loops, stem-based transitions, EQ and effects, and recording a set. Everything audible stays in the browser (the owner's decision for stems: playback and mixing only in the front end and apps). Full profile only, open to guests, like the mixer.

## Status

| Phase | State |
|---|---|
| D1 Beat grid | **Built** in the browser (`resources/js/mixer/beats.js`, `beats.worker.js`, `beats-client.js`), grid in `localStorage`. Pure logic tested; not heard on real tracks. |
| D2 Beat sync and quantize | **Built** (Sync = tempo + phase, quantized start, shift a beat). Feel unverified. |
| D3 Cues and loops | **Built** (`cues.js`; main cue, four hot cues, In/Out and beat loops 1/4 to 8, halve/double), cues in `localStorage`. The household-wide `track_cues` table and API is **not** built (decided: later, only when asked). |
| D4 Stem transitions | **Built** (Blend with bass swap, Vocals out / drums in, Drop; 8, 16, 32 bars; Mix now, cancel by touching a fader). *Echo out* waits for D5. Feel unverified. |
| D5 to D8 | Not started. |

What the code does, how it was verified and what is not verified: [stem-mixer.md](stem-mixer.md), "Beat grid, sync, cues and loops". Departures from the plan below are marked **Built:**.

## Where we start (before D1)

Built and working: two decks of kept stems (vocals, drums, bass, other) with fader, mute and solo each; tempo 0.7 to 1.4 with the pitch kept (SoundTouch) and a key shift; Sync (tempo only); nudge; equal-power crossfader; master volume; waveform of the full track and of each stem with seek; a picker with "mixes well with" (tempo and Camelot key). **A DJ needs more than this in one place: the beats.** Without a beat grid, Sync matches tempo but not phase, and nothing can be quantized, looped by beats or automated over bars. So the beat grid comes first and most of the rest hangs on it.

## What "mini DJ" means here

In scope: a good two-deck mix on a laptop or tablet, from the owner's own library, with the stems as the differentiator (swap the bass, drop the vocals, bring in the drums). Not in scope: scratching, vinyl emulation, more than two decks, streaming-service tracks (no stems), DRM, a headphone cue on a second output (see Stretch).

## Prerequisite: https for the key lock

`AudioWorklet` exists only in a secure context. On `http://remomentserver.test` or a LAN IP the mixer falls back to playing without pitch correction (tempo moves the pitch, no key shift; commit 57bcd96). A DJ on a tablet over the LAN would hit that, so the user docs' reverse-proxy page should say: for the mixer on another device, serve the app over https (FrankenPHP/Caddy can issue a local certificate). `localhost` counts as secure.

## Phases

Sizes: S = a day or less, M = a few days, L = a week or more.

### D1 Beat grid *[M; foundation]*

A grid is `{ bpm, offset, downbeat }`: the time of the first beat and which beat opens a bar. Two ways, decided in Open questions:

- **In the browser** (preferred first step, no server change): the drums stem is already decoded, and a kick and snare onset envelope of a drums-only signal is easy to track. Autocorrelate the onset envelope for the tempo (seeded by the analysed BPM, so it only has to confirm and refine it), fit the offset by maximising the onset energy on the grid, pick the downbeat from the bass and kick energy per beat. Run it in a Web Worker so the page does not stall. Cache the grid per file hash in `localStorage`.
- **On the server** (later, when accuracy matters): beats from the Essentia sidecar or librosa as a `beat_grid` in `file_analyses`, on demand like sections and stems, returned with the stems answer. Fixes the cost of computing it on every load and gives the apps the same grid.

UI: beat ticks on the waveforms (heavier on the downbeat), a "nudge the grid" control (drag or ±5 ms), and a grid-lock indicator. A track without a steady tempo (live, rubato) shows "no grid" and the beat features stay off for that deck instead of lying.

**Built:** ticks (full track and drums rows), ±5 ms grid nudge and "Downbeat here" (the downbeat guess is the weakest part), "No steady beat" state. No drag, no lock meter beyond the stored `lock` value. The tempo is found by autocorrelation for a first guess and a phase histogram over a fine grid of tempi for the result (better than the autocorrelation peak for offset and drift); the analysed BPM only chooses the octave, so grid and analysis agree and a 70 BPM kick pattern with a 140 BPM analysis gets a 140 BPM grid. The cache key is `track id : duration : total stem bytes` because the stems answer has no file hash.

### D2 Beat sync and quantize *[S to M]*

- **Sync** matches the other deck's tempo *and* phase: on press, shift this deck so its next beat lands with the other deck's current beat (a seek by a fraction of a beat, no audible jump when done at a bar boundary).
- **Quantized start**: play, cue and loop start on the next beat of the grid when the deck is synced.
- Keep the 20 ms nudge as the fine tool; add a coarse "shift a beat".

**Built:** Sync also seeks the deck, a little ahead and sample-accurately, by up to half a beat when both decks play on a grid (half and double time are matched to every second or every beat). Quantize is a per-deck toggle (default on): starting a deck while the other plays waits for the other deck's next beat and starts on the nearest beat of its own grid; cue and hot-cue setting snap to the nearest beat; a hot cue pressed while playing jumps on the next beat. Nudge now counts from where the deck will be after its 30 ms restart (it used to be 30 ms short).

### D3 Cues and loops *[M]*

- **Cue**: one main cue per deck (jump, or hold to preview) and four **hot cues** that set or jump.
- **Loops**: loop in and out, and beat loops of 1, 2, 4, 8 beats (and half and quarter). Implemented with `loop`, `loopStart`, `loopEnd` on the four buffer sources, so they stay aligned and cost no extra CPU; the loop points are in track time, so a tempo change needs nothing.
- **Persistence**: first in `localStorage` per file hash. Then a small `track_cues` table (`track_id`, `slot`, `position`, `label`), `GET|PUT /api/library/tracks/{id}/cues`, open like favourites (household-wide; no accounts), an `openapi.yaml` entry and a tool on the MCP server. Decide before building (Open questions).

**Built:** the localStorage part only (key `remoment.mixer.cues.v1.<fingerprint>`; the grid is `remoment.mixer.grid.v2.<fingerprint>`). Loops are not stored. Main cue: set while stopped, return-and-stop while playing, play while held when stopped on the cue. Pressing the active beat loop leaves it; In sets the point, Out closes the loop and playback returns to In (as on a CDJ).

### D4 Stem transitions *[M; the part nobody else has]*

With the beat grid, automate what a DJ does by hand, and a bit more because there are four stems per deck:

- **Bass swap**: the outgoing deck's bass fades out exactly when the incoming deck's bass fades in, on a bar line. Two overlapping basses are the usual mud; here it is one gain ramp each.
- **Transition presets** over N bars (8, 16, 32): *Blend* (crossfade with bass swap), *Vocals out, drums in*, *Drop* (cut everything but the drums for a bar, then bring the new track in on the downbeat), *Echo out* (needs D5).
- One button on the crossfader: **Mix now** runs the chosen preset starting at the next bar, ramps the faders through the same Web Audio gain automation the engine already uses, and leaves the controls where they ended, so the DJ can grab any fader during it (grabbing cancels the automation).

**Built:** `transitions.js` (pure: keyframes in beats per stem and for the crossfader, `buildPlan`, `planAt`), `Deck.automate` / `cancelAutomation` / `nextBeatTime(grid, lead, bar)` in `engine.js`, `mixNow` and friends in `component.js`, the Transition card on `/mixer`. Rules: the outgoing deck is the one that plays (both: the one the crossfader is on); the incoming deck is tempo-synced (and phase-synced if it already plays) and, if stopped, started from its nearest bar line on the plan's start (Drop: on the bar line the plan ends on); the plan starts on the outgoing deck's next bar line (at least 150 ms ahead); the bass swap is a 0.05-beat step on a bar line, so the two basses overlap for about 25 ms; Blend swaps at half the bars, Vocals/drums at half the bars with the new vocals at three quarters; Drop cuts everything but the drums one bar before the end. Audio runs as `setValueCurveAtTime` curves (8 points a beat) on the stem gains and the deck's crossfader gain; the sliders follow by the clock. Duration assumes the tempo does not change during the plan. The fader levels you had are what the incoming deck ends on; a muted stem is not respected during a plan.

### D5 EQ and effects *[M]*

- **3-band EQ per deck** (low shelf, mid peaking, high shelf with `BiquadFilterNode`, kill by dragging to the bottom) after the stem sum, before the deck gain. With stems, the low band is partly redundant, but people expect the knobs.
- **Filter sweep** (one knob: low-pass to the left, high-pass to the right).
- **Echo** and a short **reverb** as a send per deck (a `DelayNode` with feedback synced to the beat; reverb via `ConvolverNode` with a small generated impulse so no asset is needed). Beat-synced time needs D1.
- CPU: the SoundTouch worklet is the heavy node already; the EQ and the filter are native nodes and cheap.

### D6 Controls and layout *[M]*

- **Layout**: decks side by side on a wide screen (a tablet in landscape is the target), the mixer strip (EQ, faders, crossfader, master) in the middle, waveforms full width on top stacked so the grids line up. Today's single column stays for narrow screens.
- **Keyboard**: play/pause, cue, sync, loop, hot cues, crossfader and tempo nudges, per deck; a "?" overlay lists them.
- **MIDI** (Web MIDI, Chrome and Edge only; Safari has none): map a generic controller to the same actions; learn mode stored in `localStorage`. Optional, last.
- Touch targets of 44 px and no hover-only controls, for the tablet.

### D7 Record the set *[S to M]*

`MediaStreamAudioDestinationNode` on the master plus `MediaRecorder` (webm/opus in Chrome, mp4/aac in Safari) with a download when stopped, and a timer. Later and optional: upload the recording as a file to the library's inbox (`/api/admin/files`, admin) so it can be played and exported like any track; a mix of copyrighted tracks stays the owner's own use, the same as the library.

### D8 Set planning and auto-DJ *[M to L; can slip]*

- A **crate** (the "next up" list): drag tracks in, sorted by key and tempo distance from the playing deck (the `mixes-with` endpoint already does the matching).
- **Auto-DJ**: when a deck nears its end, load the best next crate track into the idle deck, sync it and run the chosen D4 preset by itself. The set becomes a smart-playlist player with real transitions.
- Needs stems for every track in the crate: queue `stem_audio` for the crate ahead of time (the API already queues and reports per track; the stems queue is one worker, so it is slow on a Pi and fine on a mini PC).

## Stretch (only if asked for)

- **Play the mix on the speakers**: the browser cannot push audio to Icecast directly. A route would be MediaRecorder chunks over a WebSocket to the server, ffmpeg into an Icecast mount, and the existing `stream_url` devices playing it (2 to 5 s latency, which is fine for a set but not for beat-matching by ear, which would still use the browser's own output). Same shape as the line-in and Spotify streams.
- **Headphone cue (PFL)** needs a second output device: `AudioContext.setSinkId` (Chrome) for a second context, with the cue deck's stems fed to it; Safari and iOS cannot. Without it, pre-listening means the master is heard too, which is why the DJ view needs the grid and the loops more than a cue bus.

## Constraints that shape the plan

- **Memory**: about 340 MB per deck for a 4-minute track (decoded float32 stems). A crate that preloads is out of the question on a phone; load the next track only. On iOS and low-RAM devices a "lite" load that downsamples the stems to 22 kHz when decoding halves it; decide when the iOS mixer is built.
- **Latency**: fader moves pass the SoundTouch worklet after the stem sum, so they lag by tens of milliseconds. Acceptable for blending, noticeable for hard cuts; the EQ and the deck gain sit after it, so those are immediate.
- **No effects on the server, nothing saved but cues and recordings**: the server stays a stem and metadata provider, which keeps it unchanged on a Pi.
- **Safari and iOS** are unverified for the whole mixer, and Web MIDI is absent there.

## Order

D1 (grid) → D2 (sync, quantize) → D3 (cues, loops) → D4 (stem transitions) → D5 (EQ, effects) → D6 (layout, keyboard) → D7 (record) → D8 (set planning). D4 is what makes it a stem DJ app and not a copy of an existing one, but needs D1 and D2, so do not skip ahead. D1 to D3 already make it a usable mini DJ.

## Tests and verification

- Pure logic in `math.js` or a new `beats.js` with `node --test`: tempo and offset fitting on synthetic click tracks (known grid recovered within a few ms, half and double tempo ambiguity resolved with the analysed BPM), phase-sync shift, loop arithmetic, bass-swap ramps, EQ kill gain.
- Engine behaviour in the in-app browser with synthetic stems as before (tone frequency and level asserted from an `AnalyserNode`): a loop repeats, a quantized start lands on the beat, a transition ends with the bass of exactly one deck.
- Not checkable here: how it feels. Every phase needs the owner's ears with real tracks before it counts as done.

## Open questions

1. Beat grid in the browser first, or on the server from the start? (The browser needs no deployment and works on the existing stems; the server gives one grid for every client and the future iOS mixer.)
2. Cues and loops: per browser (`localStorage`), or household-wide in the database like favourites? The second fits the no-accounts model but means a table, an endpoint and a contract entry.
3. Target device: laptop, or a tablet over the LAN (then https and the touch layout come first)?
4. Is the iOS app expected to get the DJ mode, or is it web only? If the apps follow, the beat grid and cues belong on the server in a shared shape.
5. Is recording the set wanted, and if so only a download?
6. Are the speakers (stretch) wanted at all, or is the browser's output enough?
