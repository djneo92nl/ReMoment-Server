<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start gap-4">
            <x-back-button href="{{ route('artists.index') }}" class="mt-1" />
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">{{ $artist->name }}</h1>
                    <form method="POST" action="{{ route('artists.favorite', $artist) }}">
                        @csrf
                        <button type="submit"
                                title="{{ $artist->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}"
                                class="text-xl transition-colors {{ $artist->favorited_at ? 'text-rose-500 hover:text-rose-600' : 'text-gray-300 hover:text-rose-400 dark:text-stone-600' }}">
                            <i class="{{ $artist->favorited_at ? 'fa-solid' : 'fa-regular' }} fa-heart"></i>
                        </button>
                    </form>
                </div>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">
                    {{ number_format($totalPlays) }} {{ Str::plural('play', $totalPlays) }}
                    @if($totalSeconds > 0)
                        @php $hours = floor($totalSeconds / 3600); $mins = floor(($totalSeconds % 3600) / 60); @endphp
                        &middot;
                        {{ $hours > 0 ? "{$hours}h {$mins}m" : "{$mins}m" }} listened
                    @endif
                </p>
            </div>
        </div>
    </x-slot>

    @php
        $coverAlbum = $artist->coverAlbum();
        $heroColors = $coverAlbum?->colors ?? [];
        $genres = $artist->genres();
        $country = $artist->country();
        $bio = $artist->bio();
        $similarArtists = $artist->similarArtistModels();
    @endphp

    <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8 mb-6"
         @if(count($heroColors) >= 2) style="background: linear-gradient(135deg, {{ $heroColors[0] }}22, {{ $heroColors[1] }}11)" @endif>
        <div class="flex flex-col md:flex-row items-center md:items-start gap-6 text-center md:text-left">
            <x-artwork-thumb
                :src="$coverAlbum?->images[0]['url'] ?? null"
                :colors="$heroColors"
                :seed="$artist->name"
                icon="fa-solid fa-microphone-lines"
                size="w-28 h-28 md:w-36 md:h-36"
                rounded="rounded-full"
                class="shadow-lg ring-1 ring-black/5 text-4xl"
            />
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center justify-center md:justify-start gap-1.5">
                    @foreach(array_slice($genres, 0, 8) as $genre)
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-stone-800 text-gray-600 dark:text-gray-400">{{ $genre }}</span>
                    @endforeach
                    @if($country)
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-stone-800 text-gray-600 dark:text-gray-400">
                            <i class="fa-solid fa-earth-americas mr-1"></i>{{ $country }}
                        </span>
                    @endif
                </div>

                @if($bio)
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400 leading-relaxed max-w-2xl">{{ $bio }}</p>
                @endif

                @if($playableDevices->isNotEmpty())
                    <div class="flex items-center justify-center md:justify-start gap-2 mt-4">
                        <button type="button"
                                @click="$dispatch('open-modal', 'play-artist-{{ $artist->id }}')"
                                class="flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition-colors">
                            <i class="fa-solid fa-play text-xs"></i>
                            Play all
                        </button>
                        <button type="button"
                                @click="$dispatch('open-modal', 'shuffle-artist-{{ $artist->id }}')"
                                class="flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-700 dark:text-gray-300 text-sm font-medium transition-colors">
                            <i class="fa-solid fa-shuffle text-xs"></i>
                            Shuffle
                        </button>
                    </div>
                    <x-device-picker
                        name="play-artist-{{ $artist->id }}"
                        title="Play all tracks"
                        :description="$artist->name"
                        :devices="$playableDevices"
                        :action-template="url('artists/'.$artist->id.'/play').'/{id}'"
                    />
                    <x-device-picker
                        name="shuffle-artist-{{ $artist->id }}"
                        title="Shuffle all tracks"
                        :description="$artist->name"
                        :devices="$playableDevices"
                        :action-template="url('artists/'.$artist->id.'/play').'/{id}?shuffle=1'"
                    />
                @endif
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        <!-- Left: Top tracks + Albums -->
        <div class="lg:col-span-2 space-y-6">

            @if($topTracks->isNotEmpty())
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Top Tracks</h2>
                    <div class="space-y-1">
                        @foreach($topTracks as $i => $track)
                            @php
                                $artUrl = $track->images[0]['url'] ?? $track->album?->images[0]['url'] ?? null;
                            @endphp
                            <div class="flex items-center gap-4 p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-stone-800/50 transition-colors group">
                                <span class="w-5 text-center text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">{{ $i + 1 }}</span>
                                <div class="w-9 h-9 rounded-lg overflow-hidden bg-gray-100 dark:bg-stone-800 flex-shrink-0">
                                    @if($artUrl)
                                        <img src="{{ $artUrl }}" alt="" class="w-full h-full object-cover">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <i class="fa-solid fa-music text-gray-300 dark:text-stone-600 text-xs"></i>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate flex items-center gap-1.5">
                                        {{ $track->name }}
                                        <x-source-icon :source="$track->source" />
                                    </p>
                                    @if($track->album)
                                        <p class="text-xs text-gray-400 dark:text-gray-600 truncate mt-0.5">
                                            <a href="{{ route('albums.show', $track->album) }}" class="hover:underline">{{ $track->album->name }}</a>
                                        </p>
                                    @endif
                                </div>
                                <span class="text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">
                                    {{ number_format($track->plays_count) }} {{ Str::plural('play', $track->plays_count) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($artist->albums->isNotEmpty())
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Albums</h2>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        @foreach($artist->albums as $album)
                            <a href="{{ route('albums.show', $album) }}"
                               class="group text-center">
                                <x-artwork-thumb
                                    :src="$album->images[0]['url'] ?? null"
                                    :colors="$album->colors"
                                    :seed="$album->name.$album->id"
                                    icon="fa-solid fa-compact-disc"
                                    size="w-full aspect-square"
                                    rounded="rounded-2xl"
                                    class="mb-3 shadow-sm ring-1 ring-gray-100 dark:ring-stone-800"
                                />
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate group-hover:underline">{{ $album->name }}</p>
                                @if($album->released_at)
                                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">{{ $album->released_at->format('Y') }}</p>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <!-- Right: Recent plays -->
        <div>
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
                                        {{ $play->played_at->format('M j') }}
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
                    <div class="mt-5 pt-4 border-t border-gray-100 dark:border-stone-800">
                        <a href="{{ route('history.index') }}"
                           class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">
                            Full history &rarr;
                        </a>
                    </div>
                </div>
            @endif

            @if($similarArtists->isNotEmpty())
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8 mt-6">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Similar Artists</h2>
                    <div class="flex flex-wrap gap-2">
                        @foreach($similarArtists as $similarArtist)
                            <a href="{{ route('artists.show', $similarArtist) }}"
                               class="px-3 py-1.5 rounded-full text-sm font-medium bg-gray-100 dark:bg-stone-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-stone-700 transition-colors">
                                {{ $similarArtist->name }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
