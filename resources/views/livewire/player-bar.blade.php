@php
    $panelStyle = 'background:linear-gradient(100deg, var(--glass-from) 0%, var(--glass-to) 100%);color:#fff';
@endphp
@if(!$device)
    {{-- Nothing pinned: no bar. Only the picker, floating bottom right, when it is opened from the sidebar. --}}
    <div x-data x-on:open-player-panel.window="$wire.openPanel('players')"
         @if($players !== null) x-on:keydown.escape.window="$wire.closePanel()" @endif>
        @if($players !== null)
            <div wire:click="closePanel" style="pointer-events:auto;position:fixed;inset:0;z-index:40"></div>
            <div style="pointer-events:auto;position:fixed;right:.75rem;bottom:.75rem;width:min(26rem,calc(100vw - 1.5rem));z-index:41;border-radius:.9rem;overflow:hidden;box-shadow:0 8px 30px rgba(0,0,0,.35);position:fixed;{{ $panelStyle }}"
                 x-on:close.stop="$wire.closePanel()">
                <div style="background:rgba(0,0,0,.25)">@include('livewire.partials.player-picker')</div>
            </div>
        @endif
    </div>
@else
    @php
        $isPlaying = $state === \App\Domain\Device\State::Playing;
        $isPaused = $state === \App\Domain\Device\State::Paused;
        $isActive = $isPlaying || $isPaused;
        $track = $nowPlaying?->track;
        $radio = $nowPlaying?->radio;

        ['title' => $title, 'subtitle' => $fallbackLine] = \App\Domain\Media\NowPlayingLabel::for($nowPlaying, $state, $isActive ? 'Playing' : '—');

        // Two dimmed lines like a streaming app: artist, then album (or station / device status).
        if ($track) {
            $line2 = $track->artist?->name ?: $fallbackLine;
            $line3 = $nowPlaying->album?->name ?? $radio?->name;
        } else {
            $line2 = $fallbackLine;
            $line3 = null;
        }
        if (!$isActive) {
            $line3 = ucfirst($state->value);
            $isOfflinePlayer = $state === \App\Domain\Device\State::Unreachable && is_a($device->device_driver, \App\Integrations\ReMoment\PlayerDriver::class, true);
        } elseif ($isPaused) {
            $line3 = trim(($line3 ? $line3.' · ' : '').'Paused');
        }

        // Artwork URLs are prefixed with APP_URL, which may not be the address this page is opened on: use the path.
        $thumb = \App\Domain\Artwork\LibraryItemArtwork::webUrl($artwork['proxy_120'] ?? $artwork['proxy_320'] ?? null);
        $colors = $artwork['colors'] ?? [];
        $hasColors = count($colors) >= 2;
        $background = $hasColors
            ? "linear-gradient(100deg, {$colors[0]} 0%, {$colors[1]} 100%)"
            : 'linear-gradient(100deg, var(--glass-from) 0%, var(--glass-to) 100%)';
        $accent = $hasColors ? \App\Domain\Artwork\AccentColor::for($colors, $artwork['safe_colors'] ?? []) : 'var(--accent-fallback)';

        $duration = (int) ($track?->duration ?? 0);
        $position = (int) ($nowPlaying?->position ?? 0);
        $seekable = in_array('seek', $capabilities, true);
        $canShuffle = in_array('shuffle', $capabilities, true);
        $canRepeat = in_array('repeat', $capabilities, true);
        $canVolume = in_array('volume_control', $capabilities, true);
        $canSkip = in_array('media_controls', $capabilities, true);
        $canLike = in_array('like', $capabilities, true) && $track;
        // Radio and line-in have no queue: the icon is for a playing track only
        $canQueue = in_array('queue', $capabilities, true) && $track && !$radio;

        $icon = 'background:none;border:0;cursor:pointer;color:#fff;display:flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:9999px;flex:none';
        $dim = 'opacity:.7';
        $on = "color:{$accent}";
        $rep = $modes->repeat?->value ?? 'off';
    @endphp

    <div x-data="liveDevice({{ $device->id }})" x-on:open-player-panel.window="$wire.openPanel('players')"
         @if($panel) x-on:keydown.escape.window="$wire.closePanel()" @endif
         x-on:keydown.space.window="if (!$event.target.closest('input, textarea, select, button, a, [contenteditable]')) { $event.preventDefault(); $wire.{{ $isPlaying ? 'pause' : 'play' }}() }"
         style="position:relative;padding:0 .75rem .75rem;pointer-events:auto;--accent:{{ $accent }}">

        {{-- Soft blur behind the floating bar, so the page doesn't show hard-cut in the gap around it --}}
        <div style="position:absolute;left:0;right:0;bottom:0;height:calc(100% + 1.5rem);pointer-events:none;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);-webkit-mask-image:linear-gradient(to top,#000 55%,transparent);mask-image:linear-gradient(to top,#000 55%,transparent)"></div>

        {{-- Click anywhere else closes the open panel --}}
        @if($panel)
            <div wire:click="closePanel" style="position:fixed;inset:0;z-index:-1"></div>
        @endif

        {{-- Panels float right above the bar in the same colors, like an extension of it --}}
        @if($panel)
            <div x-on:close.stop="$wire.closePanel()"
                 style="position:absolute;right:.75rem;bottom:calc(100% - .75rem);width:min(26rem,calc(100% - 1.5rem));z-index:1;max-height:65vh;display:flex;flex-direction:column;border-radius:.9rem .9rem 0 0;overflow:hidden;box-shadow:0 -8px 30px rgba(0,0,0,.25);color:#fff;background:{{ $background }}">
                <div style="background:rgba(0,0,0,.45);display:flex;flex-direction:column;min-height:0;flex:1 1 auto;overflow:hidden">
                    @if($panel === 'queue')
                        @include('livewire.partials.player-queue')
                    @elseif($panel === 'players')
                        @include('livewire.partials.player-picker')
                    @elseif($panel === 'multiroom' && $supportsMultiRoom)
                        @include('livewire.partials.multiroom-content', ['inPanel' => true])
                    @endif
                </div>
            </div>
        @endif

    {{-- Dark scrim over the artwork colors keeps white text readable whatever the cover --}}
    <div style="view-transition-name:np-bar;position:relative;z-index:1;overflow:hidden;border-radius:{{ $panel ? '.9rem 0 .9rem .9rem' : '.9rem' }};box-shadow:0 8px 30px rgba(0,0,0,.35);color:#fff;background:{{ $background }}">
        <div style="position:absolute;inset:0;background:rgba(0,0,0,.45);pointer-events:none"></div>

        <div style="position:relative;display:flex;align-items:center;gap:1rem;padding:.65rem 1rem;width:100%;box-sizing:border-box">

            {{-- Transport + progress + volume --}}
            <div class="player-center" style="flex:1.7 1 0;min-width:0;display:flex;align-items:center;gap:.25rem">
                @if($isPlaying)
                    <button type="button" wire:click="pause" title="Pause" style="{{ $icon }};width:2.9rem;height:2.9rem;background:{{ $accent }};color:#111;margin-right:.35rem">
                        <i class="fa-solid fa-pause" style="font-size:1.05rem"></i>
                    </button>
                @else
                    <button type="button" wire:click="play" title="Play" style="{{ $icon }};width:2.9rem;height:2.9rem;background:{{ $accent }};color:#111;margin-right:.35rem">
                        <i class="fa-solid fa-play" style="font-size:1.05rem;margin-left:.15rem"></i>
                    </button>
                @endif
                @if($canSkip)
                    <button type="button" wire:click="previous" title="Previous" style="{{ $icon }}"><i class="fa-solid fa-backward-step"></i></button>
                    <button type="button" wire:click="next" title="Next" style="{{ $icon }}"><i class="fa-solid fa-forward-step"></i></button>
                @endif
                @if($canShuffle)
                    <button type="button" wire:click="toggleShuffle" title="Shuffle" class="player-extra"
                            style="{{ $icon }};{{ ($modes->shuffle ?? false) ? $on : $dim }}">
                        <i class="fa-solid fa-shuffle" style="font-size:.8rem"></i>
                    </button>
                @endif
                @if($canRepeat)
                    <button type="button" wire:click="cycleRepeat" title="Repeat: {{ $rep }}" class="player-extra"
                            style="{{ $icon }};{{ $rep === 'off' ? $dim : $on }}">
                        <i class="fa-solid {{ $rep === 'one' ? 'fa-1' : 'fa-repeat' }}" style="font-size:.8rem"></i>
                    </button>
                @endif

                {{-- Progress: the track's waveform (own audio file, analysed) with the played part in the accent, else a plain bar --}}
                @if($isActive && $duration > 0)
                    <div wire:key="bar-progress-{{ $device->id }}-{{ $position }}-{{ $isPlaying ? 1 : 0 }}"
                         x-data="progressTicker({{ $device->id }}, {{ $position }}, {{ $duration }}, {{ $isPlaying ? 'true' : 'false' }})"
                         class="player-progress" style="flex:1;min-width:6rem;margin-left:.5rem;display:flex;align-items:center;gap:.6rem;font-size:.7rem;font-variant-numeric:tabular-nums">
                        <span style="opacity:.75;min-width:2.2rem;text-align:right" x-text="elapsed">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($position) }}</span>
                        @if($waveform)
                            <div @if($seekable) @click.stop="seekTo($event)" @endif
                                 style="flex:1;position:relative;height:2rem;{{ $seekable ? 'cursor:pointer' : '' }}">
                                <x-waveform :points="$waveform" :height="32" :opacity="0.35" style="position:absolute;inset:0;width:100%;height:100%;color:#fff" />
                                {{-- the played part: the same waveform in the accent, clipped to the progress --}}
                                <div style="position:absolute;inset:0;color:{{ $accent }};clip-path:inset(0 {{ 100 - min(100, (int) ($position / $duration * 100)) }}% 0 0)"
                                     :style="{ clipPath: 'inset(0 ' + (100 - pct) + '% 0 0)' }">
                                    <x-waveform :points="$waveform" :height="32" :opacity="1" style="width:100%;height:100%;display:block" />
                                </div>
                            </div>
                        @else
                            <div @if($seekable) @click.stop="seekTo($event)" @endif
                                 style="flex:1;padding:.6rem 0;position:relative;{{ $seekable ? 'cursor:pointer' : '' }}">
                                <div style="height:.25rem;border-radius:9999px;background:rgba(255,255,255,.28)">
                                    <div :style="{ width: pct + '%' }" style="height:100%;background:{{ $accent }};border-radius:9999px;position:relative;width:{{ min(100, (int) ($position / $duration * 100)) }}%">
                                        <span style="position:absolute;right:-.3rem;top:50%;width:.6rem;height:.6rem;margin-top:-.3rem;border-radius:9999px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></span>
                                    </div>
                                </div>
                            </div>
                        @endif
                        <span style="opacity:.75;min-width:2.2rem">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($duration) }}</span>
                    </div>
                @elseif($isActive)
                    <div class="player-progress" style="flex:1;min-width:6rem;margin-left:.5rem;padding:.5rem 0">
                        <div style="height:.25rem;border-radius:9999px;background:rgba(255,255,255,.28);overflow:hidden">
                            <div class="animate-pulse" style="height:100%;width:100%;background:{{ $accent }}"></div>
                        </div>
                    </div>
                @else
                    <div class="player-progress" style="flex:1"></div>
                @endif

                @if($canVolume)
                    <div class="player-extra" wire:key="bar-volume-{{ (int) $volume }}-{{ $muted ? 1 : 0 }}" x-data="{ vol: {{ (int) $volume }}, drag: false, t: null,
                                  wheel(e) { this.vol = Math.max(0, Math.min(100, this.vol + (e.deltaY < 0 ? 5 : -5))); this.drag = true; clearTimeout(this.t); this.t = setTimeout(() => { this.drag = false; $wire.setVolume(parseInt(this.vol)) }, 400) } }"
                         style="display:flex;align-items:center;gap:.2rem;width:7.5rem;flex:none">
                        <button type="button" wire:click="toggleMute" @wheel.prevent="wheel($event)" title="{{ $muted ? 'Unmute' : 'Mute' }} ({{ (int) $volume }})" style="{{ $icon }}">
                            <i class="fa-solid {{ $muted ? 'fa-volume-xmark' : ((int) $volume < 35 ? 'fa-volume-low' : 'fa-volume-high') }}" style="font-size:.85rem"></i>
                        </button>
                        <div style="position:relative;flex:1;height:1.5rem;display:flex;align-items:center">
                            <div style="width:100%;height:.25rem;border-radius:9999px;background:rgba(255,255,255,.3);overflow:hidden">
                                <div :style="{ width: vol + '%' }" style="height:100%;background:{{ $accent }};width:{{ (int) $volume }}%"></div>
                            </div>
                            {{-- knob, so the position is visible even at a low volume --}}
                            <div :style="{ left: 'calc(' + vol + '% - ' + (vol / 100 * 10) + 'px)' }" style="position:absolute;top:50%;width:10px;height:10px;margin-top:-5px;border-radius:9999px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.4);pointer-events:none;left:calc({{ (int) $volume }}% - {{ (int) $volume / 100 * 10 }}px)"></div>
                            <span x-show="drag" x-text="vol" style="position:absolute;bottom:100%;margin-bottom:.1rem;padding:.05rem .4rem;border-radius:.4rem;background:rgba(0,0,0,.75);font-size:.7rem;font-variant-numeric:tabular-nums;pointer-events:none;transform:translateX(-50%);display:none"
                                  :style="{ left: 'calc(' + vol + '% - ' + (vol / 100 * 10) + 'px + 5px)' }"></span>
                            <input type="range" min="0" max="100" x-model="vol" @input="drag = true" @wheel.prevent="wheel($event)" @change="drag = false; $wire.setVolume(parseInt(vol))"
                                   style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;margin:0">
                        </div>
                    </div>
                @endif
            </div>

            {{-- Artwork + what is playing --}}
            <a href="{{ route('devices.show', $device) }}" style="display:flex;align-items:center;gap:.75rem;flex:1 1 0;min-width:0;max-width:20rem;color:inherit;text-decoration:none">
                <div style="view-transition-name:np-cover;width:3rem;height:3rem;border-radius:.4rem;overflow:hidden;flex:none;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;box-shadow:0 2px 10px rgba(0,0,0,.35)">
                    @if($thumb)
                        <img src="{{ $thumb }}" alt="" style="width:100%;height:100%;object-fit:cover">
                    @else
                        <i class="fa-solid {{ $radio ? 'fa-tower-broadcast' : 'fa-music' }}" style="opacity:.6;font-size:1.1rem"></i>
                    @endif
                </div>
                <div style="min-width:0">
                    <p class="truncate" style="font-size:.9rem;font-weight:600;line-height:1.3">@if($track && ($trackUrl = \App\Domain\Library\TrackLink::forNowPlaying($nowPlaying)))<a href="{{ $trackUrl }}" style="color:inherit">{{ $title }}</a>@else{{ $title }}@endif</p>
                    @if($line2)<p class="truncate" style="font-size:.78rem;line-height:1.3;{{ $dim }}">{{ $line2 }}</p>@endif
                    @if(!empty($isOfflinePlayer))<p class="truncate" style="font-size:.78rem;line-height:1.3;opacity:.7">Offline · <a href="{{ route('player') }}" target="_blank" rel="noopener" style="text-decoration:underline">open the player</a></p>
                    @elseif($line3)<p class="truncate" style="font-size:.78rem;line-height:1.3;opacity:.5">{{ $line3 }}</p>@endif
                </div>
            </a>

            {{-- Actions --}}
            <div style="flex:none;display:flex;align-items:center;justify-content:flex-end;gap:.15rem">
                @if($canLike)
                    <button type="button" wire:click="toggleLike" title="{{ ($modes->liked ?? false) ? 'Remove from liked' : 'Like' }}" class="player-extra"
                            style="{{ $icon }};{{ ($modes->liked ?? false) ? $on : '' }}">
                        <i class="{{ ($modes->liked ?? false) ? 'fa-solid' : 'fa-regular' }} fa-heart"></i>
                    </button>
                @endif
                @if($canQueue)
                    <button type="button" wire:click="togglePanel('queue')" title="Up next" class="player-extra"
                            style="{{ $icon }};{{ $panel === 'queue' ? $on : '' }}">
                        <i class="fa-solid fa-list-ul" style="font-size:.85rem"></i>
                    </button>
                @endif
                @if($supportsMultiRoom)
                    <button type="button" wire:click="togglePanel('multiroom')" title="Multiroom"
                            style="{{ $icon }};{{ count($group) > 1 ? 'background:var(--fav);color:var(--fav-ink)' : '' }}">
                        <i class="fa-solid fa-layer-group" style="font-size:.85rem"></i>
                    </button>
                @endif
                <a href="/receiver?device={{ $device->id }}" title="Open receiver" class="player-extra" style="{{ $icon }};text-decoration:none">
                    <i class="fa-solid fa-expand" style="font-size:.8rem"></i>
                </a>
                <span class="player-extra" style="width:1px;height:1.8rem;background:rgba(255,255,255,.25);margin:0 .4rem"></span>
                {{-- The pinned player(s): opens the picker, which also has "No player" --}}
                <button type="button" wire:click="togglePanel('players')"
                        title="Playing on {{ count($group) > 1 ? implode(' + ', $group) : $device->device_name }} — change player"
                        style="{{ $icon }};{{ $panel === 'players' ? $on : '' }}">
                    <i class="fa-solid {{ count($group) > 1 ? 'fa-layer-group' : 'fa-tv' }}"></i>
                </button>
            </div>
        </div>

        @if($controlError)
            <p style="position:relative;padding:0 1rem .4rem;font-size:.75rem;color:var(--on-glass-error)">{{ $controlError }}</p>
        @endif

    </div>
    </div>
@endif
