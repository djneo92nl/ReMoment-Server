@if(!$device)
    <button type="button" @click="$dispatch('open-modal', 'select-player')"
            style="width:100%;display:flex;align-items:center;justify-content:center;gap:.5rem;padding:.9rem 1rem"
            class="text-sm font-medium text-gray-600 dark:text-gray-300">
        <i class="fa-solid fa-circle-play text-indigo-500"></i>
        Choose a player to play on
    </button>
@else
    @php
        $isPlaying = $state === \App\Domain\Device\State::Playing;
        $isPaused = $state === \App\Domain\Device\State::Paused;
        $isActive = $isPlaying || $isPaused;
        $track = $nowPlaying?->track;
        $radio = $nowPlaying?->radio;

        ['title' => $title, 'subtitle' => $line2] = \App\Domain\Media\NowPlayingLabel::for($nowPlaying, $state, $isActive ? 'Playing' : '—');

        // Artwork URLs are prefixed with APP_URL, which may not be the address this page is opened on: use the path.
        $thumb = \App\Domain\Artwork\LibraryItemArtwork::webUrl($artwork['proxy_120'] ?? $artwork['proxy_320'] ?? null);
        $colors = $artwork['colors'] ?? [];
        $hasColors = count($colors) >= 2;
        $background = $hasColors
            ? "linear-gradient(100deg, {$colors[0]} 0%, {$colors[1]} 100%)"
            : 'linear-gradient(100deg, #2b2b2b 0%, #1a1a1a 100%)';

        $duration = (int) ($track?->duration ?? 0);
        $position = (int) ($nowPlaying?->position ?? 0);
        $seekable = in_array('seek', $capabilities, true);
        $canShuffle = in_array('shuffle', $capabilities, true);
        $canRepeat = in_array('repeat', $capabilities, true);
        $canVolume = in_array('volume_control', $capabilities, true);
        $canSkip = in_array('media_controls', $capabilities, true);

        $icon = 'background:none;border:0;cursor:pointer;color:#fff;display:flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:9999px';
        $dim = 'opacity:.7';
    @endphp

    {{-- Dark scrim over the artwork colors keeps white text readable whatever the cover --}}
    <div x-data="liveDevice({{ $device->id }})"
         style="position:relative;color:#fff;background:{{ $background }}">
        <div style="position:absolute;inset:0;background:rgba(0,0,0,.45);pointer-events:none"></div>

        <div style="position:relative;display:flex;align-items:center;gap:1rem;padding:.6rem 1rem;width:100%;box-sizing:border-box">

            {{-- Artwork + what is playing --}}
            <div style="display:flex;align-items:center;gap:.8rem;flex:1 1 0;min-width:0">
                <a href="{{ route('devices.show', $device) }}" style="display:flex;align-items:center;gap:.8rem;min-width:0;color:inherit;text-decoration:none">
                    <div style="width:3.75rem;height:3.75rem;border-radius:.6rem;overflow:hidden;flex:none;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;box-shadow:0 2px 10px rgba(0,0,0,.35)">
                        @if($thumb)
                            <img src="{{ $thumb }}" alt="" style="width:100%;height:100%;object-fit:cover">
                        @else
                            <i class="fa-solid {{ $radio ? 'fa-tower-broadcast' : 'fa-music' }}" style="opacity:.6;font-size:1.2rem"></i>
                        @endif
                    </div>
                </a>
                <div style="min-width:0">
                    <a href="{{ route('devices.show', $device) }}" style="color:inherit;text-decoration:none;display:block;min-width:0">
                        <p class="truncate" style="font-size:.95rem;font-weight:600;line-height:1.25">{{ $title }}</p>
                        @if($line2)
                            <p class="truncate" style="font-size:.8rem;{{ $dim }}">{{ $line2 }}</p>
                        @endif
                    </a>
                    {{-- The pinned player(s): tapping them opens the picker to swap --}}
                    <button type="button" @click="$dispatch('open-modal', 'select-player')" title="Change player"
                            style="display:flex;align-items:center;gap:.3rem;max-width:100%;margin-top:.15rem;padding:.1rem .5rem;border-radius:9999px;border:0;cursor:pointer;color:#fff;background:rgba(255,255,255,.18);font-size:.72rem">
                        <i class="fa-solid {{ count($group) > 1 ? 'fa-layer-group' : 'fa-tv' }}" style="font-size:.6rem"></i>
                        <span class="truncate">{{ count($group) > 1 ? implode(' + ', $group) : $device->device_name }}@if($isPaused) &middot; Paused @elseif(!$isActive) &middot; {{ ucfirst($state->value) }} @endif</span>
                        <i class="fa-solid fa-chevron-up" style="font-size:.55rem;opacity:.8"></i>
                    </button>
                </div>
            </div>

            {{-- Transport + progress --}}
            <div class="player-center" style="flex:1.4 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:.15rem">
                <div style="display:flex;align-items:center;gap:.35rem">
                    @if($canShuffle)
                        <button type="button" wire:click="toggleShuffle" title="Shuffle" class="player-extra"
                                style="{{ $icon }};{{ ($modes->shuffle ?? false) ? '' : $dim }}">
                            <i class="fa-solid fa-shuffle" style="font-size:.8rem"></i>
                        </button>
                    @endif
                    @if($canSkip)
                        <button type="button" wire:click="previous" title="Previous" style="{{ $icon }}"><i class="fa-solid fa-backward-step"></i></button>
                    @endif
                    @if($isPlaying)
                        <button type="button" wire:click="pause" title="Pause" style="{{ $icon }};width:2.9rem;height:2.9rem;background:#fff;color:#111">
                            <i class="fa-solid fa-pause" style="font-size:1.1rem"></i>
                        </button>
                    @else
                        <button type="button" wire:click="play" title="Play" style="{{ $icon }};width:2.9rem;height:2.9rem;background:#fff;color:#111">
                            <i class="fa-solid fa-play" style="font-size:1.1rem;margin-left:.15rem"></i>
                        </button>
                    @endif
                    @if($canSkip)
                        <button type="button" wire:click="next" title="Next" style="{{ $icon }}"><i class="fa-solid fa-forward-step"></i></button>
                    @endif
                    @if($canRepeat)
                        @php($rep = $modes->repeat?->value ?? 'off')
                        <button type="button" wire:click="cycleRepeat" title="Repeat: {{ $rep }}" class="player-extra"
                                style="{{ $icon }};{{ $rep === 'off' ? $dim : '' }};position:relative">
                            <i class="fa-solid {{ $rep === 'one' ? 'fa-1' : 'fa-repeat' }}" style="font-size:.8rem"></i>
                        </button>
                    @endif
                </div>

                @if($isActive && $duration > 0)
                    <div wire:key="bar-progress-{{ $device->id }}-{{ $position }}-{{ $isPlaying ? 1 : 0 }}"
                         x-data="progressTicker({{ $device->id }}, {{ $position }}, {{ $duration }}, {{ $isPlaying ? 'true' : 'false' }})"
                         class="player-progress" style="width:100%;max-width:34rem;display:flex;align-items:center;gap:.6rem;font-size:.7rem;font-variant-numeric:tabular-nums">
                        <span style="opacity:.75;min-width:2.2rem;text-align:right" x-text="elapsed">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($position) }}</span>
                        <div @if($seekable) @click.stop="seekTo($event)" @endif
                             style="flex:1;padding:.5rem 0;{{ $seekable ? 'cursor:pointer' : '' }}">
                            <div style="height:.25rem;border-radius:9999px;background:rgba(255,255,255,.28);overflow:hidden">
                                <div :style="{ width: pct + '%' }" style="height:100%;background:#fff;border-radius:9999px;width:{{ min(100, (int) ($position / $duration * 100)) }}%"></div>
                            </div>
                        </div>
                        <span style="opacity:.75;min-width:2.2rem">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($duration) }}</span>
                    </div>
                @elseif($isActive)
                    <div class="player-progress" style="width:100%;max-width:34rem;padding:.5rem 0">
                        <div style="height:.25rem;border-radius:9999px;background:rgba(255,255,255,.28);overflow:hidden">
                            <div class="animate-pulse" style="height:100%;width:100%;background:rgba(255,255,255,.7)"></div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Volume + device --}}
            <div style="flex:1 1 0;min-width:0;display:flex;align-items:center;justify-content:flex-end;gap:.4rem">
                @if($canVolume)
                    <div class="player-extra" wire:key="bar-volume-{{ (int) $volume }}-{{ $muted ? 1 : 0 }}" x-data="{ vol: {{ (int) $volume }} }"
                         style="display:flex;align-items:center;gap:.4rem;width:12rem">
                        <button type="button" wire:click="toggleMute" title="{{ $muted ? 'Unmute' : 'Mute' }}" style="{{ $icon }}">
                            <i class="fa-solid {{ $muted ? 'fa-volume-xmark' : ((int) $volume < 35 ? 'fa-volume-low' : 'fa-volume-high') }}" style="font-size:.85rem"></i>
                        </button>
                        <div style="position:relative;flex:1;height:1.5rem;display:flex;align-items:center">
                            <div style="width:100%;height:.3rem;border-radius:9999px;background:rgba(255,255,255,.3);overflow:hidden">
                                <div :style="{ width: vol + '%' }" style="height:100%;background:#fff;width:{{ (int) $volume }}%"></div>
                            </div>
                            {{-- knob, so the position is visible even at a low volume --}}
                            <div :style="{ left: 'calc(' + vol + '% - ' + (vol / 100 * 10) + 'px)' }" style="position:absolute;top:50%;width:10px;height:10px;margin-top:-5px;border-radius:9999px;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.4);pointer-events:none;left:calc({{ (int) $volume }}% - {{ (int) $volume / 100 * 10 }}px)"></div>
                            <input type="range" min="0" max="100" x-model="vol" @change="$wire.setVolume(parseInt(vol))"
                                   style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;margin:0">
                        </div>
                        <span x-text="vol" style="min-width:1.4rem;text-align:right;font-size:.7rem;opacity:.75;font-variant-numeric:tabular-nums">{{ (int) $volume }}</span>
                    </div>
                @endif
                <a href="/receiver?device={{ $device->id }}" target="_blank" title="Open receiver" class="player-extra" style="{{ $icon }};text-decoration:none">
                    <i class="fa-solid fa-expand" style="font-size:.8rem"></i>
                </a>
                @if($supportsMultiRoom)
                    <button type="button" @click="$wire.loadMultiRoomData(); $dispatch('open-modal', 'multiroom-{{ $device->id }}')" title="Multiroom"
                            style="{{ $icon }};{{ count($group) > 1 ? 'background:#fbbf24;color:#1f2937' : '' }}">
                        <i class="fa-solid fa-layer-group" style="font-size:.85rem"></i>
                    </button>
                @endif
                <button type="button" @click="$dispatch('open-modal', 'select-player')" title="Change player" style="{{ $icon }}">
                    <i class="fa-solid fa-tv"></i>
                </button>
                <form method="POST" action="{{ route('player.clear') }}" style="display:flex">
                    @csrf
                    @method('DELETE')
                    <button type="submit" title="Unpin player" style="{{ $icon }};{{ $dim }}">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </form>
            </div>
        </div>

        @if($controlError)
            <p style="position:relative;padding:0 1rem .4rem;font-size:.75rem;color:#fecaca">{{ $controlError }}</p>
        @endif

    @if($supportsMultiRoom)
        @include('livewire.partials.multiroom-modal')
    @endif
    </div>
@endif
