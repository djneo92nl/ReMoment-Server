{{-- One deck of the stem mixer. $side is "a" or "b"; every expression reads the Alpine `deck.{side}` of the page's `mixer` component. --}}
@php($d = "deck.$side")
<x-card class="p-5 sm:p-6">
    <div class="flex items-center gap-4">
        <div class="h-14 w-14 flex-none overflow-hidden rounded-xl bg-gray-100 dark:bg-stone-800">
            <img x-show="{{ $d }}.track?.artwork?.proxy_120" :src="{{ $d }}.track?.artwork?.proxy_120" alt="" class="h-full w-full object-cover">
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Deck {{ strtoupper($side) }}</p>
            <p class="truncate text-base font-medium text-gray-900 dark:text-gray-100" x-text="{{ $d }}.track?.name ?? 'No track yet'"></p>
            <p class="truncate text-sm text-gray-500" x-text="{{ $d }}.track ? [{{ $d }}.track.artist_name, {{ $d }}.track.album_name].filter(Boolean).join(' · ') : 'Pick one below'"></p>
        </div>
        <x-secondary-button size="sm" x-show="{{ $d }}.track" @click="unload('{{ $side }}')" aria-label="Unload deck {{ strtoupper($side) }}"><i class="fa-solid fa-xmark text-xs"></i></x-secondary-button>
    </div>

    <div class="mt-5" x-show="['checking', 'loading'].includes({{ $d }}.status)" x-cloak>
        <p class="text-sm text-gray-500"><i class="fa-solid fa-spinner fa-spin mr-1.5"></i><span x-text="{{ $d }}.status === 'loading' ? 'Loading the ' + {{ $d }}.stems.length + ' stems' + ({{ $d }}.megabytes ? ' (about ' + {{ $d }}.megabytes + ' MB in memory)' : '') : 'Looking for the stems'"></span></p>
    </div>

    <div class="mt-5 space-y-3" x-show="{{ $d }}.status === 'needs_stems'" x-cloak>
        <p class="text-sm text-gray-500">This track has no stems yet. Separating them takes a few minutes of CPU on the server and is kept afterwards.</p>
        <p class="text-sm text-gray-500" x-show="{{ $d }}.error" x-text="{{ $d }}.error"></p>
        <x-primary-button type="button" size="md" @click="requestStems('{{ $side }}')" x-bind:disabled="!{{ $d }}.available"><i class="fa-solid fa-wand-magic-sparkles text-xs"></i>Make stems</x-primary-button>
        <p class="text-xs text-gray-400 dark:text-gray-600" x-show="!{{ $d }}.available">The stems service is not set up on this server (STEMS_URL).</p>
    </div>

    <div class="mt-5" x-show="{{ $d }}.status === 'processing'" x-cloak>
        <p class="text-sm text-gray-500"><i class="fa-solid fa-hourglass-half mr-1.5"></i><span x-text="{{ $d }}.jobState === 'running' ? 'Separating the stems' : 'Waiting for the stems queue'"></span><span x-show="{{ $d }}.estimate" x-text="' (an estimate: about ' + Math.max(1, Math.round({{ $d }}.estimate / 60)) + ' min)'"></span></p>
    </div>

    <div class="mt-5" x-show="{{ $d }}.status === 'error'" x-cloak>
        <x-alert type="error"><span x-text="{{ $d }}.error"></span></x-alert>
    </div>

    <div class="mt-5 space-y-5" x-show="{{ $d }}.status === 'ready'" x-cloak>
        {{-- Transport --}}
        <div>
            <div class="flex items-center gap-3">
                <x-primary-button type="button" size="md" @click="togglePlay('{{ $side }}')" x-bind:aria-label="{{ $d }}.playing ? 'Pause deck {{ strtoupper($side) }}' : 'Play deck {{ strtoupper($side) }}'">
                    <i class="fa-solid" :class="{{ $d }}.playing ? 'fa-pause' : 'fa-play'"></i>
                </x-primary-button>
                <span class="w-24 flex-none text-sm tabular-nums text-gray-500"><span x-text="time({{ $d }}.pos)"></span> / <span x-text="time({{ $d }}.dur)"></span></span>
                @include('mixer._slider', ['value' => "$d.pos", 'min' => '0', 'max' => "Math.max(1, $d.dur)", 'step' => '0.1', 'input' => "seek('$side', \$event.target.value)", 'label' => "'Position of deck ".strtoupper($side)."'"])
            </div>
            <div class="mt-1 h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-stone-800" aria-hidden="true">
                <div class="h-full rounded-full bg-gray-400 dark:bg-stone-500 transition-[width] duration-100" :style="'width:' + Math.round({{ $d }}.level * 100) + '%'"></div>
            </div>
        </div>

        {{-- Waveforms: the full track and each stem on one time axis; click to seek --}}
        <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Waveforms</p>
            <div class="relative cursor-pointer select-none space-y-1" data-test="waveforms" @click="seekFromClick('{{ $side }}', $event)">
                <template x-for="row in ['mix', ...{{ $d }}.stems]" :key="row">
                    <div class="relative" :class="row === 'mix' ? 'h-14' : 'h-8'" :data-wave="row">
                        <span class="pointer-events-none absolute left-1 top-0 z-10 text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400" x-text="row === 'mix' ? 'Full track' : row"></span>
                        <div class="absolute inset-0 rounded bg-gray-50 dark:bg-stone-900/60"></div>
                        <svg class="absolute inset-0 h-full w-full text-gray-300 dark:text-stone-700 transition-opacity" :class="row !== 'mix' && ({{ $d }}.muted[row] || (Object.values({{ $d }}.solo).some(Boolean) && !{{ $d }}.solo[row])) ? 'opacity-30' : ''" viewBox="0 0 800 100" preserveAspectRatio="none" aria-hidden="true"><path :d="{{ $d }}.wave?.[row]" fill="currentColor"></path></svg>
                        <svg class="absolute inset-0 h-full w-full text-gray-700 dark:text-stone-300" :style="'clip-path:' + playedClip('{{ $side }}')" viewBox="0 0 800 100" preserveAspectRatio="none" aria-hidden="true"><path :d="{{ $d }}.wave?.[row]" fill="currentColor"></path></svg>
                        {{-- Beat ticks of the grid, heavier on the first beat of a bar --}}
                        <svg x-show="{{ $d }}.grid && (row === 'mix' || row === 'drums')" class="pointer-events-none absolute inset-0 h-full w-full text-sky-600 dark:text-sky-400" viewBox="0 0 800 100" preserveAspectRatio="none" aria-hidden="true" data-test="beat-ticks">
                            <path :d="{{ $d }}.ticks.beat" fill="none" stroke="currentColor" stroke-width="1" stroke-opacity="0.45" vector-effect="non-scaling-stroke"></path>
                            <path :d="{{ $d }}.ticks.bar" fill="none" stroke="currentColor" stroke-width="2" stroke-opacity="0.9" vector-effect="non-scaling-stroke"></path>
                        </svg>
                    </div>
                </template>
                {{-- Loop region and cue markers --}}
                <div x-show="{{ $d }}.loop" class="pointer-events-none absolute inset-y-0 rounded-xl bg-emerald-500/20 ring-1 ring-emerald-500/50" :style="{{ $d }}.loop ? 'left:' + at('{{ $side }}', {{ $d }}.loop.start) + '%;width:' + (at('{{ $side }}', {{ $d }}.loop.end) - at('{{ $side }}', {{ $d }}.loop.start)) + '%' : ''"></div>
                <div x-show="{{ $d }}.cue !== null" class="pointer-events-none absolute inset-y-0 w-0.5 bg-amber-500" :style="'left:' + at('{{ $side }}', {{ $d }}.cue) + '%'" title="Cue"></div>
                <template x-for="(spot, slot) in {{ $d }}.hot" :key="slot">
                    <div x-show="spot !== null" class="pointer-events-none absolute inset-y-0 w-0.5 bg-sky-500" :style="'left:' + at('{{ $side }}', spot) + '%'">
                        <span class="absolute -top-0.5 left-0.5 text-[10px] font-semibold leading-none text-sky-600 dark:text-sky-400" x-text="slot + 1"></span>
                    </div>
                </template>
                <div class="pointer-events-none absolute inset-y-0 w-px bg-red-500" :style="'left:' + ({{ $d }}.dur > 0 ? ({{ $d }}.pos / {{ $d }}.dur) * 100 : 0) + '%'"></div>
            </div>
        </div>

        {{-- The four stems --}}
        <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Stems</p>
            <template x-for="stem in {{ $d }}.stems" :key="stem">
                <div class="flex items-center gap-3">
                    <span class="w-14 flex-none text-sm capitalize text-gray-700 dark:text-gray-300" x-text="stem"></span>
                    @include('mixer._slider', ['value' => "$d.levels[stem]", 'min' => '0', 'max' => '1', 'step' => '0.01', 'input' => "setLevel('$side', stem, \$event.target.value)", 'label' => "stem + ' level, deck ".strtoupper($side)."'"])
                    <span class="w-9 flex-none text-right text-xs tabular-nums text-gray-500" x-text="percent({{ $d }}.levels[stem])"></span>
                    <x-secondary-button size="sm" class="w-8 justify-center" @click="toggle('{{ $side }}', 'muted', stem)" x-bind:aria-pressed="{{ $d }}.muted[stem]" x-bind:aria-label="'Mute ' + stem" x-bind:class="{{ $d }}.muted[stem] ? '!bg-red-600 !border-red-600 !text-white' : ''">M</x-secondary-button>
                    <x-secondary-button size="sm" class="w-8 justify-center" @click="toggle('{{ $side }}', 'solo', stem)" x-bind:aria-pressed="{{ $d }}.solo[stem]" x-bind:aria-label="'Solo ' + stem" x-bind:class="{{ $d }}.solo[stem] ? '!bg-gray-900 dark:!bg-stone-600 !border-gray-900 dark:!border-stone-600 !text-white' : ''">S</x-secondary-button>
                </div>
            </template>
        </div>

        {{-- Tempo and key --}}
        <div>
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Tempo</p>
                <p class="text-sm tabular-nums text-gray-700 dark:text-gray-300" x-text="tempoLabel('{{ $side }}')"></p>
            </div>
            <div class="flex items-center gap-3">
                @include('mixer._slider', ['value' => "$d.rate", 'min' => 'RATE_MIN', 'max' => 'RATE_MAX', 'step' => '0.001', 'input' => "setRate('$side', \$event.target.value)", 'label' => "'Tempo of deck ".strtoupper($side)."'"])
                <x-secondary-button size="sm" @click="sync('{{ $side }}')" x-bind:disabled="!canSync('{{ $side }}')" title="Match the tempo of the other deck">Sync</x-secondary-button>
                <x-secondary-button size="sm" @click="setRate('{{ $side }}', 1)" aria-label="Reset the tempo"><i class="fa-solid fa-rotate-left text-xs"></i></x-secondary-button>
            </div>
            <p x-show="!pitchCorrection" x-cloak class="mt-2 text-xs text-amber-700 dark:text-amber-400">This page is not on https, so the browser has no pitch correction: tempo changes the pitch too and the key shift is off.</p>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-500">Key</span>
                    <span class="min-w-8 text-sm font-medium tabular-nums text-gray-700 dark:text-gray-300" x-text="keyLabel('{{ $side }}') ?? '–'"></span>
                    <x-secondary-button size="sm" @click="setSemitones('{{ $side }}', Math.max(-12, {{ $d }}.semitones - 1))" aria-label="Key down a semitone"><i class="fa-solid fa-minus text-xs"></i></x-secondary-button>
                    <span class="w-8 text-center text-xs tabular-nums text-gray-500" x-text="({{ $d }}.semitones > 0 ? '+' : '') + {{ $d }}.semitones"></span>
                    <x-secondary-button size="sm" @click="setSemitones('{{ $side }}', Math.min(12, {{ $d }}.semitones + 1))" aria-label="Key up a semitone"><i class="fa-solid fa-plus text-xs"></i></x-secondary-button>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-sm text-gray-500">Nudge</span>
                    <x-secondary-button size="sm" @click="nudge('{{ $side }}', -20)" aria-label="Nudge 20 milliseconds back"><i class="fa-solid fa-backward-step text-xs"></i></x-secondary-button>
                    <x-secondary-button size="sm" @click="nudge('{{ $side }}', 20)" aria-label="Nudge 20 milliseconds forward"><i class="fa-solid fa-forward-step text-xs"></i></x-secondary-button>
                    <span class="ml-2 text-sm text-gray-500">Beat</span>
                    <x-secondary-button size="sm" @click="shiftBeat('{{ $side }}', -1)" x-bind:disabled="!({{ $d }}.grid?.bpm ?? {{ $d }}.bpm)" aria-label="Shift a beat back"><i class="fa-solid fa-angles-left text-xs"></i></x-secondary-button>
                    <x-secondary-button size="sm" @click="shiftBeat('{{ $side }}', 1)" x-bind:disabled="!({{ $d }}.grid?.bpm ?? {{ $d }}.bpm)" aria-label="Shift a beat forward"><i class="fa-solid fa-angles-right text-xs"></i></x-secondary-button>
                </div>
            </div>
        </div>

        {{-- Beat grid --}}
        <div data-test="beat-grid">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Beat grid</p>
                <p class="text-sm tabular-nums text-gray-700 dark:text-gray-300" x-text="gridLabel('{{ $side }}')"></p>
            </div>
            <div class="mt-2" x-show="['nogrid', 'error'].includes({{ $d }}.gridState)" x-cloak>
                <x-secondary-button size="sm" @click="retryGrid('{{ $side }}')">Find the beats again</x-secondary-button>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2" x-show="{{ $d }}.grid">
                <x-secondary-button size="sm" @click="{{ $d }}.quantize = !{{ $d }}.quantize" x-bind:aria-pressed="{{ $d }}.quantize" x-bind:class="{{ $d }}.quantize ? '!bg-gray-900 dark:!bg-stone-600 !border-gray-900 dark:!border-stone-600 !text-white' : ''" title="Starts, cues and loops land on the beat">Quantize</x-secondary-button>
                <span class="ml-2 text-sm text-gray-500">Grid</span>
                <x-secondary-button size="sm" @click="nudgeGrid('{{ $side }}', -5)" aria-label="Move the grid 5 milliseconds earlier"><i class="fa-solid fa-angle-left text-xs"></i>5 ms</x-secondary-button>
                <x-secondary-button size="sm" @click="nudgeGrid('{{ $side }}', 5)" aria-label="Move the grid 5 milliseconds later">5 ms<i class="fa-solid fa-angle-right text-xs"></i></x-secondary-button>
                <x-secondary-button size="sm" @click="setDownbeat('{{ $side }}')" title="Make the beat at the playhead the first of a bar">Downbeat here</x-secondary-button>
            </div>
        </div>

        {{-- Cues and loops --}}
        <div data-test="cues-loops">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Cues</p>
            <div class="flex flex-wrap items-center gap-2">
                <x-secondary-button size="sm" @pointerdown.prevent="cuePress('{{ $side }}')" @pointerup="cueRelease('{{ $side }}')" @pointerleave="cueRelease('{{ $side }}')" @keydown.space.prevent="cuePress('{{ $side }}')" @keyup.space="cueRelease('{{ $side }}')" title="Set the cue (stopped), return to it (playing), play from it while held (stopped on the cue)">
                    <span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>Cue
                </x-secondary-button>
                <template x-for="slot in HOT_CUES" :key="slot">
                    <span class="inline-flex items-center">
                        <x-secondary-button size="sm" class="min-w-14 justify-center tabular-nums" @click="hotCue('{{ $side }}', slot - 1)" x-bind:aria-label="'Hot cue ' + slot + ({{ $d }}.hot[slot - 1] === null ? ', empty: set here' : ', jump')">
                            <span x-text="slot"></span><span class="text-gray-400 dark:text-gray-500" x-text="{{ $d }}.hot[slot - 1] === null ? '' : time({{ $d }}.hot[slot - 1])"></span>
                        </x-secondary-button>
                        <button type="button" x-show="{{ $d }}.hot[slot - 1] !== null" @click="clearHot('{{ $side }}', slot - 1)" class="ml-1 h-6 w-6 rounded-full text-xs text-gray-400 hover:text-gray-700 focus-visible:outline focus-visible:outline-2 dark:text-gray-600 dark:hover:text-gray-300" x-bind:aria-label="'Clear hot cue ' + slot"><i class="fa-solid fa-xmark"></i></button>
                    </span>
                </template>
            </div>

            <p class="mb-2 mt-4 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Loop</p>
            <div class="flex flex-wrap items-center gap-2">
                <x-secondary-button size="sm" @click="loopIn('{{ $side }}')" x-bind:class="{{ $d }}.markIn !== null ? '!border-emerald-500 !text-emerald-700 dark:!text-emerald-400' : ''">In</x-secondary-button>
                <x-secondary-button size="sm" @click="loopOut('{{ $side }}')" x-bind:disabled="{{ $d }}.markIn === null">Out</x-secondary-button>
                <x-secondary-button size="sm" @click="exitLoop('{{ $side }}')" x-bind:disabled="!{{ $d }}.loop">Exit</x-secondary-button>
                <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-stone-700"></span>
                <template x-for="beats in LOOP_BEATS" :key="beats">
                    <x-secondary-button size="sm" class="min-w-10 justify-center tabular-nums" @click="beatLoop('{{ $side }}', beats)" x-bind:disabled="!{{ $d }}.grid" x-bind:aria-pressed="{{ $d }}.loop?.beats === beats" x-bind:aria-label="'Loop ' + beatLabel(beats) + ' beats'" x-bind:class="{{ $d }}.loop?.beats === beats ? '!bg-emerald-600 !border-emerald-600 !text-white' : ''"><span x-text="beatLabel(beats)"></span></x-secondary-button>
                </template>
                <x-secondary-button size="sm" @click="scaleLoop('{{ $side }}', 0.5)" x-bind:disabled="!{{ $d }}.loop" aria-label="Halve the loop">½×</x-secondary-button>
                <x-secondary-button size="sm" @click="scaleLoop('{{ $side }}', 2)" x-bind:disabled="!{{ $d }}.loop" aria-label="Double the loop">2×</x-secondary-button>
            </div>
            <p class="mt-2 text-xs text-gray-400 dark:text-gray-600" x-show="!{{ $d }}.grid && {{ $d }}.gridState !== 'analysing'">Beat loops, quantize and beat sync need a steady beat; cues and In/Out loops work without one.</p>
        </div>
    </div>
</x-card>
