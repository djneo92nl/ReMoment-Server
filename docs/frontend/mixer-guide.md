# Using the stem mixer: a hands-on guide

For someone sitting at `/mixer` who wants to try the DJ features (beat grid, sync, cues, loops, transitions). What each control does under the hood is in [stem-mixer.md](stem-mixer.md) and [dj-mode.md](dj-mode.md). Headphones or speakers on the computer you browse from: the sound comes out of the browser, not out of the server's players.

## Before you start

- You need two tracks **with kept stems**. A track has them once you pressed **Make stems** on it (a few minutes of server CPU, kept afterwards). Pick two tracks that are about the same tempo, for example from the "Mixes well with" list under the picker.
- Over plain `http://` (a LAN address or `remomentserver.test`) the browser has no pitch correction: changing the tempo also changes the pitch, and the key buttons do nothing. Everything else works. `https://` or `localhost` gives the full set.
- Do the steps in order the first time. Each one says what you should see or hear.

## 1. Load the decks

1. Open `/mixer`. Search for a track (two letters or more), press **A** on a result. Press **B** on a second one.
2. Each deck shows "Loading the 4 stems", then the controls. Under **Beat grid** the deck first says "Finding the beats…" (a second or two), then e.g. `124.00 BPM · beat 3 of 4`.
3. Blue ticks appear on the **Full track** and **Drums** waveforms; the taller ones open a bar. If the deck says **No steady beat, beat features are off**, that track has no usable grid (live recording, free tempo): cues, In/Out loops and tempo Sync still work, but not Quantize, beat loops, phase sync or Mix now.

**Check the grid first**: press play on a deck and watch the red playhead cross the ticks while you listen: a tick should sit on each kick. If all ticks are a bit early or late, use **Grid ‹ 5 ms / 5 ms ›** until they fit. If the tall ticks are not on the first beat of the bar, put the playhead on the right beat and press **Downbeat here**. This is saved in your browser for that track.

## 2. Play and mix by ear

- **▶** plays a deck, the bar under it is its level, a click on the waveform seeks. **Play or pause both** starts both in the same instant.
- **Stems**: a fader, **M**ute and **S**olo per stem. Try soloing the drums, then the bass.
- **Crossfader**: left = only deck A, right = only deck B. **Master** is the total volume.

## 3. Match the two tracks

1. Play deck A. Press **▶** on deck B while A plays. With **Quantize** on (the dark button under Beat grid; default) deck B waits for A's next beat and starts on one of its own beats, so you start roughly in step.
2. Press **Sync** on deck B: it takes A's tempo (also in half or double time when that is closer) and, because both play on a grid, jumps up to half a beat so the beats coincide. Listen to both with the crossfader in the middle: the kicks should sound as one.
3. Still a little off? **Nudge** ◂ ▸ moves B by 20 ms; **Beat** ‹‹ ›› moves it a whole beat (the beats stay matched, useful for bringing a different bar in).
4. Tempo slider and **Key** −/+ change tempo and key by hand (key only on https).

## 4. Cues

- **Cue** (amber): while stopped away from the cue, it **sets** the cue at the playhead (snapped to the beat when Quantize is on). While playing it **returns** to the cue and stops. While stopped *on* the cue, **press and hold** to play from it, release to jump back.
- **1 to 4** (hot cues): an empty one **sets** at the playhead (the button then shows the time); a set one **jumps** there (on the next beat while playing and quantized); **×** clears it. They show as markers on the waveform.
- Cues are remembered per browser and per track.

## 5. Loops

- **In**, then **Out** at the playhead: the loop starts and playback returns to In. **Exit** leaves it. The green band on the waveform is the loop.
- **1/4 1/2 1 2 4 8**: a loop of that many beats from the nearest beat (needs a grid). Press the lit one again to leave. **½× / 2×** halve or double the running loop.

## 6. Transition: let the mixer do the swap

1. Deck A plays (the song going out). Deck B has its track loaded, grid shown, not playing (or already playing in step).
2. In the **Transition** card choose a preset and the bars, press **Mix now**. It says "Starts on the next bar, in x s", then "Bar n of N". The stem faders and the crossfader move by themselves, and deck B starts on a bar line by itself.
   - **Blend**: crossfade over all bars; half way the bass swaps from A to B on a bar line (listen for one bass at a time).
   - **Vocals out, drums in**: A's vocals fade out first and B's drums come in, then the bass and the rest swap at the half and B's vocals arrive at three quarters.
   - **Drop**: A plays on; for the last bar only A's drums are left; then B starts in full on the bar line.
3. **Touch any fader or the crossfader, or press Cancel** to stop it where it is: everything stays as it was at that moment and keeps playing.
4. After it ends, the faders stay where the transition left them (deck A: probably silent, bass at 0). To mix back, put A's faders up again before the next Mix now.

## If something does not work

| You see | Because |
|---|---|
| "Mix now needs a beat grid on both decks." | One deck says "No steady beat" or is still "Finding the beats…". |
| "Start the deck that plays out first." | Neither deck is playing. |
| Beat loops greyed out | The deck has no grid. |
| Grid ticks clearly off the kicks | Nudge the grid; or the track has a swing or a changing tempo. |
| Sync sounds a half beat off | The grid of one deck sits on the off-beat: fix it with Grid 5 ms or Downbeat here. |
| Tempo change makes voices chipmunk | Plain `http://`: no pitch correction (see above). |

## What to report

For each step: did it do what the guide says, was the grid right on your tracks (which track, which tempo), and how did the beat match, the loops and the bass swap *sound* (these have only been tested with synthetic tones). A track where the grid is wrong is the most useful report.
