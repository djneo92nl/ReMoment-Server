@php
    $state      = $device->state;
    // If the listener heartbeat has expired, treat the device as unreachable
    // regardless of whatever stale state is in the cache.
    if (!$listenerRunning) {
        $state = \App\Domain\Device\State::Unreachable;
    }
    $nowPlaying = \App\Domain\Device\DeviceCache::getNowPlaying($device->id);
    $isPlaying  = $state === \App\Domain\Device\State::Playing;
    $isPaused   = $state === \App\Domain\Device\State::Paused;
    $isActive   = $isPlaying || $isPaused;
    $showUrl    = route('devices.show', $device);

    // Progress — only when we have a track with a known duration
    $hasDuration = $nowPlaying?->track && ($nowPlaying->track->duration ?? 0) > 0;
    $pct = $hasDuration
        ? min(100, (int)(($nowPlaying->position / $nowPlaying->track->duration) * 100))
        : 0;

    // Artwork proxy and dominant colors
    $artUrl = $nowPlaying ? \App\Domain\Artwork\ArtworkCache::extractImageUrl($nowPlaying) : null;
    $artwork = $artUrl ? \App\Domain\Artwork\ArtworkCache::get($artUrl) : null;
    $gradientColors = $artwork['colors'] ?? [];
    $gradientStyle = count($gradientColors) >= 2
        ? "background: linear-gradient(135deg, {$gradientColors[0]}55 0%, {$gradientColors[1]}33 100%);"
        : '';
@endphp

<div wire:poll.1s
     class="{{ $gradientStyle ? '' : 'bg-white dark:bg-stone-900' }} rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden hover:shadow-xl transition-all {{ (!$standalone && $isActive) ? 'md:col-span-2' : '' }}"
     style="{{ $gradientStyle }}">

    {{-- Inner wrapper handles click-to-navigate; buttons capture their own clicks first --}}
    <div class="p-8 cursor-pointer"
         onclick="if(!event.target.closest('button,a,input'))window.location='{{ $showUrl }}'">

        {{-- ── Header ── --}}
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3 min-w-0">
                @if($isPlaying)
                    <span class="relative flex w-3.5 h-3.5 flex-shrink-0">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full w-3.5 h-3.5 bg-emerald-500"></span>
                    </span>
                    <span class="text-sm font-medium uppercase tracking-wider text-emerald-600 dark:text-emerald-500 truncate">Now Playing</span>
                @elseif($isPaused)
                    <span class="w-3.5 h-3.5 rounded-full bg-amber-500 flex-shrink-0"></span>
                    <span class="text-sm font-medium uppercase tracking-wider text-amber-600 dark:text-amber-500 truncate">Paused</span>
                @elseif($state === \App\Domain\Device\State::Standby)
                    <span class="w-3.5 h-3.5 rounded-full bg-red-500 flex-shrink-0"></span>
                    <span class="text-sm font-medium uppercase tracking-wider text-red-600 dark:text-red-500 truncate">Standby</span>
                @else
                    <span class="w-3.5 h-3.5 rounded-full bg-gray-400 flex-shrink-0"></span>
                    <span class="text-sm font-medium uppercase tracking-wider text-gray-500 dark:text-gray-500 truncate">Unreachable</span>
                @endif
            </div>

            <div class="flex items-center gap-3 flex-shrink-0">
                <span class="text-sm text-gray-500 dark:text-gray-400 hidden sm:block truncate max-w-40">{{ $device->device_name }}</span>
                <a href="{{ $showUrl }}"
                   class="flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 dark:text-gray-600 hover:text-gray-700 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors"
                   title="Open {{ $device->device_name }}">
                    <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i>
                </a>
            </div>
        </div>

        {{-- ── Active state (Playing / Paused) ── --}}
        @if($isActive)

            @if($nowPlaying)
                <div class="flex flex-col {{ $isActive ? 'lg:flex-row' : '' }} gap-6">

                    {{-- Artwork --}}
                    @if($artUrl)
                        <div class="w-full lg:w-48 aspect-square rounded-2xl overflow-hidden shadow-lg ring-1 ring-gray-200 dark:ring-stone-800 flex-shrink-0">
                            <img src="{{ $artUrl }}" alt="Artwork" class="w-full h-full object-cover">
                        </div>
                    @else
                        <div class="w-full lg:w-48 aspect-square rounded-2xl bg-gray-100 dark:bg-stone-800 flex items-center justify-center flex-shrink-0">
                            @if($nowPlaying->radio)
                                <i class="fa-solid fa-tower-broadcast text-4xl text-gray-300 dark:text-stone-600"></i>
                            @elseif($nowPlaying->source)
                                <i class="fa-solid fa-plug text-4xl text-gray-300 dark:text-stone-600"></i>
                            @else
                                <i class="fa-solid fa-music text-4xl text-gray-300 dark:text-stone-600"></i>
                            @endif
                        </div>
                    @endif

                    <div class="flex-1 min-w-0 space-y-5">

                        {{-- Track / Radio / Source info --}}
                        <div class="min-w-0">
                            @if($nowPlaying->radio && !$nowPlaying->track)
                                {{-- Pure radio: no track metadata --}}
                                <h2 class="text-2xl lg:text-3xl font-medium tracking-tight dark:text-emerald-50 truncate">{{ $nowPlaying->radio->name }}</h2>
                                <p class="mt-1.5 text-base text-gray-500 dark:text-gray-400">Radio</p>
                            @elseif($nowPlaying->track)
                                {{-- Track (music or radio-with-track-info) --}}
                                <h2 class="text-2xl lg:text-3xl font-medium tracking-tight dark:text-emerald-50 truncate">{{ $nowPlaying->track->name }}</h2>
                                <p class="mt-1.5 text-base text-gray-500 dark:text-gray-400 truncate">
                                    {{ $nowPlaying->track->artist?->name }}
                                    @if($nowPlaying->album?->name)
                                        · {{ $nowPlaying->album->name }}
                                    @elseif($nowPlaying->radio?->name)
                                        · {{ $nowPlaying->radio->name }}
                                    @endif
                                </p>
                            @elseif($nowPlaying->source)
                                {{-- External source (line-in, HDMI, etc.) --}}
                                <h2 class="text-2xl lg:text-3xl font-medium tracking-tight dark:text-emerald-50 truncate">{{ $nowPlaying->source->name }}</h2>
                                <p class="mt-1.5 text-base text-gray-500 dark:text-gray-400 truncate">
                                    {{ $nowPlaying->source->sourceType }}
                                    @if($nowPlaying->source->connector)
                                        · {{ $nowPlaying->source->connector }}
                                    @endif
                                </p>
                            @else
                                <h2 class="text-2xl font-medium text-gray-500 dark:text-gray-400">Playing</h2>
                            @endif
                        </div>

                        {{-- Progress bar — only for timed tracks --}}
                        @if($hasDuration)
                            <div>
                                <div class="flex justify-between text-xs text-gray-400 dark:text-gray-600 mb-1.5">
                                    <span>{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($nowPlaying->position) }}</span>
                                    <span>{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($nowPlaying->track->duration) }}</span>
                                </div>
                                <div class="h-1.5 bg-gray-200 dark:bg-stone-700 rounded-full overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-red-500 to-rose-600 rounded-full transition-all duration-500"
                                         style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        @else
                            {{-- Radio / no duration: animated bar --}}
                            <div class="h-1.5 bg-gray-200 dark:bg-stone-700 rounded-full overflow-hidden">
                                <div class="h-full w-full bg-gradient-to-r from-red-500 to-rose-600 rounded-full origin-left animate-pulse opacity-60"></div>
                            </div>
                        @endif

                        {{-- Playback controls --}}
                        <div class="flex items-center gap-8">
                            <button wire:click="previous"
                                    class="text-gray-400 dark:text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 text-xl transition-colors">
                                <i class="fa-solid fa-backward-step"></i>
                            </button>
                            @if($isPlaying)
                                <button wire:click="pause"
                                        class="text-gray-900 dark:text-gray-100 hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-pause-circle text-5xl drop-shadow"></i>
                                </button>
                            @else
                                <button wire:click="play"
                                        class="text-gray-900 dark:text-gray-100 hover:scale-110 transition-transform">
                                    <i class="fa-solid fa-play-circle text-5xl drop-shadow"></i>
                                </button>
                            @endif
                            <button wire:click="next"
                                    class="text-gray-400 dark:text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 text-xl transition-colors">
                                <i class="fa-solid fa-forward-step"></i>
                            </button>
                        </div>

                        {{-- Volume --}}
                        @if($volume > 0)
                            <div x-data="{ vol: {{ $volume }} }"
                                 x-init="$watch('$wire.volume', v => vol = v)"
                                 class="flex items-center gap-3">
                                <i class="fa-solid fa-volume-high text-gray-400 dark:text-gray-600 text-sm w-4 flex-shrink-0"></i>
                                <div class="relative flex-1 max-w-xs">
                                    <div class="h-1.5 bg-gray-200 dark:bg-stone-700 rounded-full overflow-hidden">
                                        <div class="h-full bg-gray-500 dark:bg-stone-400 rounded-full"
                                             :style="'width: ' + vol + '%'"></div>
                                    </div>
                                    <input type="range" min="0" max="100"
                                           x-model="vol"
                                           @change="$wire.setVolume(vol)"
                                           class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                                </div>
                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 w-6 text-right" x-text="vol"></span>
                            </div>
                        @endif

                    </div>
                </div>

            @else
                {{-- Playing but nothing cached yet --}}
                <div class="h-36 bg-gray-50 dark:bg-stone-800/50 rounded-2xl flex items-center justify-center mb-5">
                    <i class="fa-solid fa-music text-5xl text-gray-200 dark:text-stone-700 animate-pulse"></i>
                </div>
            @endif

        {{-- ── Standby ── --}}
        @elseif($state === \App\Domain\Device\State::Standby)
            <div class="h-36 bg-gray-100 dark:bg-stone-800 rounded-2xl flex items-center justify-center mb-5">
                <i class="fa-solid fa-power-off text-5xl text-gray-300 dark:text-stone-600"></i>
            </div>
            <div x-data="{ vol: {{ $volume }} }"
                 x-init="$watch('$wire.volume', v => vol = v)"
                 class="flex items-center gap-3 text-sm">
                <i class="fa-solid fa-volume-high text-gray-400 dark:text-gray-600 w-4 flex-shrink-0"></i>
                <div class="relative flex-1">
                    <div class="h-1.5 bg-gray-200 dark:bg-stone-700 rounded-full overflow-hidden">
                        <div class="h-full bg-gray-400 dark:bg-stone-500 rounded-full"
                             :style="'width: ' + vol + '%'"></div>
                    </div>
                    <input type="range" min="0" max="100"
                           x-model="vol"
                           @change="$wire.setVolume(vol)"
                           class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                </div>
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 w-6 text-right" x-text="vol"></span>
            </div>

        {{-- ── Unreachable ── --}}
        @else
            <div class="h-36 bg-gray-50 dark:bg-stone-800/50 rounded-2xl flex items-center justify-center mb-5">
                <i class="fa-solid fa-wifi text-5xl text-gray-200 dark:text-stone-700"></i>
            </div>
            <p class="text-sm text-gray-400 dark:text-gray-600">
                {{ $device->last_seen ? 'Last seen ' . $device->last_seen->diffForHumans() : 'Never seen' }}
            </p>
        @endif

        {{-- ── Footer ── --}}
        <div class="mt-5 pt-4 border-t border-gray-100 dark:border-stone-800 flex items-center justify-between text-xs text-gray-400 dark:text-gray-600">
            <span>{{ $device->device_brand_name }} · {{ $device->device_product_type }}</span>
            <div class="flex items-center gap-3">
                @if($supportsMultiRoom && $state !== \App\Domain\Device\State::Unreachable)
                    <button x-data @click.stop="$wire.loadMultiRoomData(); $dispatch('open-multiroom-{{ $device->id }}')"
                            class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium text-blue-500 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                        <i class="fa-solid fa-layer-group"></i>
                        <span>Multiroom</span>
                    </button>
                @endif
                @if($state !== \App\Domain\Device\State::Unreachable)
                    <button wire:click="standby"
                            class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                        <i class="fa-solid fa-power-off"></i>
                        <span>Standby</span>
                    </button>
                @endif
                @include('livewire.partials.listener-badge')
            </div>
        </div>

    </div>

    {{-- ── Multiroom modal ── --}}
    @if($supportsMultiRoom)
        <div x-data="{ open: false }"
             x-on:open-multiroom-{{ $device->id }}.window="open = true"
             x-on:keydown.escape.window="open = false"
             x-cloak>

            <div x-show="open"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0">

                {{-- Backdrop --}}
                <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="open = false"></div>

                {{-- Panel --}}
                <div class="relative w-full max-w-md bg-white dark:bg-stone-900 rounded-2xl shadow-xl overflow-hidden"
                     @click.stop
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100">

                    <div class="flex items-center justify-between px-6 pt-6 pb-4 border-b border-gray-100 dark:border-stone-800">
                        <div class="flex items-center gap-2.5">
                            <i class="fa-solid fa-layer-group text-blue-500 dark:text-blue-400"></i>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Multiroom</h3>
                            <span class="text-xs text-gray-400 dark:text-gray-600">{{ $device->device_name }}</span>
                        </div>
                        <button @click="open = false"
                                class="flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                            <i class="fa-solid fa-xmark text-xs"></i>
                        </button>
                    </div>

                    <div class="px-3 py-3 space-y-1 max-h-[70vh] overflow-y-auto">

                        @if(!$multiRoomDataLoaded)
                            <div class="px-3 py-8 text-center">
                                <i class="fa-solid fa-circle-notch fa-spin text-gray-300 dark:text-stone-600 text-2xl"></i>
                            </div>
                        @else
                            @if($multiroomError)
                                <div class="px-3 py-2 text-xs text-red-500 dark:text-red-400">{{ $multiroomError }}</div>
                            @endif

                            {{-- Sessions to join (shown when not playing, or always for context) --}}
                            @if(count($joinableSessions) > 0)
                                <p class="px-3 pt-2 pb-1 text-xs font-medium text-gray-400 dark:text-gray-600 uppercase tracking-wider">Join a session</p>
                                @foreach($joinableSessions as $session)
                                    <div class="flex items-center gap-3 px-3 py-2 rounded-xl group hover:bg-gray-50 dark:hover:bg-stone-800">
                                        <i class="fa-solid fa-music text-gray-400 dark:text-gray-500 text-xs w-4 text-center flex-shrink-0"></i>
                                        <span class="flex-1 text-sm text-gray-800 dark:text-gray-200 truncate">{{ $session['device_name'] }}</span>
                                        <button wire:click="joinSession({{ $session['id'] }})"
                                                wire:loading.attr="disabled"
                                                @click="open = false"
                                                class="flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 hover:bg-blue-100 dark:hover:bg-blue-900/30 hover:text-blue-600 dark:hover:text-blue-400 transition-colors opacity-0 group-hover:opacity-100">
                                            <i class="fa-solid fa-arrow-right-to-bracket text-xs"></i>
                                        </button>
                                    </div>
                                @endforeach
                            @elseif($state !== \App\Domain\Device\State::Playing)
                                <div class="px-3 py-8 text-center">
                                    <p class="text-sm text-gray-400 dark:text-gray-600">No active sessions to join.</p>
                                </div>
                            @endif

                            {{-- Current listeners (shown when playing) --}}
                            @if($state === \App\Domain\Device\State::Playing)
                                @if(count($currentListeners) > 0)
                                    <p class="px-3 pt-2 pb-1 text-xs font-medium text-gray-400 dark:text-gray-600 uppercase tracking-wider">Listening now</p>
                                    @foreach($currentListeners as $listener)
                                        <div class="flex items-center gap-3 px-3 py-2 rounded-xl group">
                                            <span class="relative flex w-2.5 h-2.5 flex-shrink-0">
                                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                                <span class="relative inline-flex rounded-full w-2.5 h-2.5 bg-emerald-500"></span>
                                            </span>
                                            <span class="flex-1 text-sm text-gray-800 dark:text-gray-200 truncate">{{ $listener['device_name'] }}</span>
                                        </div>
                                    @endforeach
                                @endif

                                {{-- Invitable devices --}}
                                @if(count($invitableDevices) > 0)
                                    <p class="px-3 pt-2 pb-1 text-xs font-medium text-gray-400 dark:text-gray-600 uppercase tracking-wider">Invite to session</p>
                                    @foreach($invitableDevices as $guest)
                                        <div class="flex items-center gap-3 px-3 py-2 rounded-xl group hover:bg-gray-50 dark:hover:bg-stone-800">
                                            <i class="fa-solid fa-speaker text-gray-400 dark:text-gray-500 text-xs w-4 text-center flex-shrink-0"></i>
                                            <span class="flex-1 text-sm text-gray-800 dark:text-gray-200 truncate">{{ $guest['device_name'] }}</span>
                                            <button wire:click="inviteDevice({{ $guest['id'] }})"
                                                    wire:loading.attr="disabled"
                                                    class="flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 hover:bg-emerald-100 dark:hover:bg-emerald-900/30 hover:text-emerald-600 dark:hover:text-emerald-400 transition-colors opacity-0 group-hover:opacity-100">
                                                <i class="fa-solid fa-plus text-xs"></i>
                                            </button>
                                        </div>
                                    @endforeach
                                @endif

                                @if(count($currentListeners) === 0 && count($invitableDevices) === 0)
                                    <p class="px-3 py-4 text-xs text-gray-400 dark:text-gray-600 text-center">No other {{ $device->device_brand_name }} devices available.</p>
                                @endif

                                {{-- Leave current session --}}
                                @if(count($joinableSessions) > 0)
                                    <div class="px-3 pt-2 pb-1 border-t border-gray-100 dark:border-stone-800 mt-2"></div>
                                    <button wire:click="leaveSession" @click="open = false"
                                            class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-sm text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                                        <i class="fa-solid fa-arrow-right-from-bracket text-xs w-4 text-center flex-shrink-0"></i>
                                        Leave session
                                    </button>
                                @endif
                            @endif

                            {{-- Leave option for non-playing joined device --}}
                            @if($state !== \App\Domain\Device\State::Playing && count($joinableSessions) === 0)
                                {{-- Device might be in a session but not the primary player --}}
                                <div class="px-3 pt-2">
                                    <button wire:click="leaveSession" @click="open = false"
                                            class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-sm text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                                        <i class="fa-solid fa-arrow-right-from-bracket text-xs w-4 text-center flex-shrink-0"></i>
                                        Leave session
                                    </button>
                                </div>
                            @endif
                        @endif

                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
