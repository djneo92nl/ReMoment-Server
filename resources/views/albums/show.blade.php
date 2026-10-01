<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start gap-4">
            <x-back-button href="{{ route('artists.show', $album->artist) }}" class="mt-1" />
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">{{ $album->name }}</h1>
                    <form method="POST" action="{{ route('albums.favorite', $album) }}">
                        @csrf
                        <button type="submit"
                                title="{{ $album->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}"
                                class="text-xl transition-colors {{ $album->favorited_at ? 'text-rose-500 hover:text-rose-600' : 'text-gray-300 hover:text-rose-400 dark:text-stone-600' }}">
                            <i class="{{ $album->favorited_at ? 'fa-solid' : 'fa-regular' }} fa-heart"></i>
                        </button>
                    </form>
                </div>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">
                    <a href="{{ route('artists.show', $album->artist) }}" class="hover:underline">{{ $album->artist->name }}</a>
                    @if($album->released_at)
                        &middot; {{ $album->released_at->format('Y') }}
                    @endif
                    @if($totalPlays > 0)
                        &middot; {{ number_format($totalPlays) }} {{ Str::plural('play', $totalPlays) }}
                    @endif
                </p>
            </div>
        </div>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">

        <!-- Left: Album art + tracklist -->
        <div class="lg:col-span-2 space-y-6">

            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">

                @php
                    $artUrl = $album->images[0]['url'] ?? null;
                    $colors = $album->colors ?? [];
                @endphp

                {{-- Hero --}}
                <div class="flex gap-6 p-6 md:p-8"
                     @if(count($colors) >= 2) style="background: linear-gradient(135deg, {{ $colors[0] }}22, {{ $colors[1] }}11)" @endif>
                    <x-artwork-thumb
                        :src="$artUrl"
                        :colors="$colors"
                        :seed="$album->name.$album->id"
                        icon="fa-solid fa-compact-disc"
                        size="w-32 h-32"
                        rounded="rounded-2xl"
                        class="shadow-lg ring-1 ring-black/5 text-3xl"
                    />
                    <div class="flex-1 min-w-0 pt-2">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-1">Album</p>
                        <h2 class="text-xl font-medium text-gray-900 dark:text-gray-100 leading-snug">{{ $album->name }}</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-500 mt-1">{{ $album->artist->name }}</p>
                        @if($album->label())
                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">{{ $album->label() }}</p>
                        @endif
                        @php $genres = $album->artist->genres() @endphp
                        @if(count($genres))
                            <div class="flex flex-wrap gap-1.5 mt-2">
                                @foreach(array_slice($genres, 0, 5) as $genre)
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 dark:bg-stone-800 text-gray-500 dark:text-gray-500">{{ $genre }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if(count($colors) > 0)
                            <div class="flex gap-1.5 mt-4">
                                @foreach(array_slice($colors, 0, 5) as $color)
                                    <div class="w-5 h-5 rounded-full shadow-sm ring-1 ring-black/10" style="background: {{ $color }}"></div>
                                @endforeach
                            </div>
                        @endif
                        @if($playableDevices->isNotEmpty() && $album->tracks->isNotEmpty())
                            <div class="flex items-center gap-2 mt-4">
                                <button type="button"
                                        @click="$dispatch('open-modal', 'play-album-{{ $album->id }}')"
                                        class="flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition-colors">
                                    <i class="fa-solid fa-play text-xs"></i>
                                    Play all
                                </button>
                                <button type="button"
                                        @click="$dispatch('open-modal', 'shuffle-album-{{ $album->id }}')"
                                        class="flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-700 dark:text-gray-300 text-sm font-medium transition-colors">
                                    <i class="fa-solid fa-shuffle text-xs"></i>
                                    Shuffle
                                </button>
                            </div>
                            <x-device-picker
                                name="play-album-{{ $album->id }}"
                                title="Play album"
                                :description="$album->name"
                                :devices="$playableDevices"
                                :action-template="url('albums/'.$album->id.'/play').'/{id}'"
                            />
                            <x-device-picker
                                name="shuffle-album-{{ $album->id }}"
                                title="Shuffle album"
                                :description="$album->name"
                                :devices="$playableDevices"
                                :action-template="url('albums/'.$album->id.'/play').'/{id}?shuffle=1'"
                            />
                        @endif
                    </div>
                </div>

                {{-- Tracklist --}}
                @if($album->tracks->isNotEmpty())
                    <div class="border-t border-gray-100 dark:border-stone-800 divide-y divide-gray-50 dark:divide-stone-800/50">
                        @foreach($album->tracks as $i => $track)
                            @php $dlnaUrl = $track->getDlnaUrl(); $lyrics = $track->lyricsPlain(); @endphp
                            <div x-data="{ lyricsOpen: false }">
                                <div class="flex items-center gap-4 px-6 py-3 hover:bg-gray-50 dark:hover:bg-stone-800/30 transition-colors group">
                                    <span class="w-5 text-center text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">{{ $i + 1 }}</span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate flex items-center gap-1.5">
                                            {{ $track->name }}
                                            <x-source-icon :source="$track->source" />
                                        </p>
                                        @if($track->duration)
                                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">{{ gmdate('g:i', $track->duration) }}</p>
                                        @endif
                                    </div>
                                    @if(isset($track->plays_count) && $track->plays_count > 0)
                                        <span class="text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">
                                            {{ number_format($track->plays_count) }}×
                                        </span>
                                    @endif
                                    @if($lyrics)
                                        <button @click="lyricsOpen = !lyricsOpen"
                                                :class="lyricsOpen ? 'opacity-100 text-blue-500 dark:text-blue-400' : 'opacity-0 group-hover:opacity-100 text-gray-400'"
                                                class="transition-opacity w-7 h-7 rounded-full flex items-center justify-center hover:bg-gray-100 dark:hover:bg-stone-700 flex-shrink-0"
                                                title="Lyrics">
                                            <i class="fa-solid fa-align-left text-xs"></i>
                                        </button>
                                    @endif
                                    @if($dlnaUrl && $playableDevices->isNotEmpty())
                                        <button @click="$dispatch('open-modal', 'play-track-{{ $track->id }}')"
                                                class="opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-stone-700 flex-shrink-0"
                                                title="Play on device">
                                            <i class="fa-solid fa-play text-xs"></i>
                                        </button>
                                        <x-device-picker
                                            name="play-track-{{ $track->id }}"
                                            title="Play track"
                                            :description="$track->name"
                                            :devices="$playableDevices"
                                            :action-template="url('tracks/'.$track->id.'/play').'/{id}'"
                                        />
                                    @endif
                                </div>
                                @if($lyrics)
                                    <div x-show="lyricsOpen" class="px-14 py-4 border-t border-gray-50 dark:border-stone-800/50 bg-gray-50/50 dark:bg-stone-800/20">
                                        <pre class="text-xs text-gray-600 dark:text-gray-400 whitespace-pre-wrap max-h-72 overflow-y-auto font-sans leading-relaxed">{{ $lyrics }}</pre>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <!-- Right: Stats + recent plays -->
        <div class="space-y-6">

            @if($totalPlays > 0)
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Stats</h2>
                    <dl class="space-y-4 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-500">Total plays</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-200">{{ number_format($totalPlays) }}</dd>
                        </div>
                        @if($totalSeconds > 0)
                            @php $hours = floor($totalSeconds / 3600); $mins = floor(($totalSeconds % 3600) / 60); @endphp
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-500">Listening time</dt>
                                <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $hours > 0 ? "{$hours}h {$mins}m" : "{$mins}m" }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-500">Tracks played</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $album->tracks->filter(fn ($t) => $t->plays_count > 0)->count() }} / {{ $album->tracks->count() }}</dd>
                        </div>
                    </dl>
                </div>
            @endif

            @if($recentPlays->isNotEmpty())
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Recent Plays</h2>
                    <div class="space-y-3">
                        @foreach($recentPlays as $play)
                            @php
                                $playSource = $play->radioStation?->name
                                    ?? ($play->radio_name ? $play->radio_name : null)
                                    ?? match($play->source_type) {
                                        'spotify' => 'Spotify',
                                        'tidal'   => 'Tidal',
                                        'deezer'  => 'Deezer',
                                        default   => null,
                                    };
                            @endphp
                            <div class="flex items-center gap-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ $play->track->name }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-600 truncate mt-0.5">
                                        {{ $play->played_at->format('M j, H:i') }}
                                        @if($play->device)
                                            &middot; {{ $play->device->device_name }}
                                        @endif
                                        @if($playSource)
                                            &middot; {{ $playSource }}
                                        @endif
                                    </p>
                                </div>
                                @if($play->skipped)
                                    <span class="text-[10px] text-amber-500 dark:text-amber-400 flex-shrink-0" title="Skipped">
                                        <i class="fa-solid fa-forward-step"></i>
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
