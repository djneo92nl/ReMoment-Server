<x-app-layout>
    <x-slot name="header">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Library</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">What's new and what's playing</p>
            </div>
            <x-library-scope :scope="$scope" />
        </div>
    </x-slot>

    <div class="space-y-8">

        {{-- Recently added albums --}}
        <div>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Recently Added</h2>
                <a href="{{ route('albums.index', ['sort' => 'recent']) }}" class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">
                    All albums &rarr;
                </a>
            </div>

            @if($recentAlbums->isEmpty())
                <div class="bg-white dark:bg-stone-900 rounded-3xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-10 text-center">
                    <p class="text-gray-400 dark:text-gray-600 text-sm">No albums yet — start listening to build your library</p>
                </div>
            @else
                <x-album-grid>
                    @foreach($recentAlbums as $album)
                        <x-album-card :album="$album" />
                    @endforeach
                </x-album-grid>
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Recently played --}}
            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Recently Played</h2>
                @if($recentPlays->isEmpty())
                    <p class="text-sm text-gray-400 dark:text-gray-600">Nothing played yet.</p>
                @else
                    <div class="space-y-3">
                        @foreach($recentPlays as $play)
                            @php $track = $play->track; @endphp
                            <div class="flex items-center gap-3">
                                <x-artwork-thumb
                                    :src="$track?->images[0]['url'] ?? $track?->album?->images[0]['url'] ?? null"
                                    :colors="$track?->album?->colors"
                                    :seed="$track?->name.$track?->id"
                                    icon="fa-solid fa-music"
                                    size="w-10 h-10"
                                    rounded="rounded-lg"
                                />
                                <div class="flex-1 min-w-0">
                                    @if($track?->album)
                                        <a href="{{ route('albums.show', $track->album) }}#track-{{ $track->id }}" class="block text-sm text-gray-800 dark:text-gray-200 truncate hover:underline">{{ $track->name }}</a>
                                    @else
                                        <p class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ $track?->name }}</p>
                                    @endif
                                    <p class="text-xs text-gray-400 dark:text-gray-600 truncate mt-0.5">
                                        @if($track?->artist)
                                            <a href="{{ route('artists.show', $track->artist) }}" class="hover:underline">{{ $track->artist->name }}</a>
                                        @endif
                                        @if($play->device)
                                            &middot; {{ $play->device->device_name }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Top artists --}}
            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Top Artists</h2>
                @if($topArtists->isEmpty())
                    <p class="text-sm text-gray-400 dark:text-gray-600">No plays yet.</p>
                @else
                    <div class="space-y-3">
                        @foreach($topArtists as $artist)
                            @php($coverAlbum = $artist->coverAlbum())
                            <a href="{{ route('artists.show', $artist) }}" class="flex items-center gap-3 group">
                                <x-artwork-thumb
                                    :src="$coverAlbum?->images[0]['url'] ?? null"
                                    :colors="$coverAlbum?->colors"
                                    :seed="$artist->name"
                                    icon="fa-solid fa-microphone-lines"
                                    size="w-10 h-10"
                                    rounded="rounded-lg"
                                />
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-800 dark:text-gray-200 truncate group-hover:underline">{{ $artist->name }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">
                                        {{ number_format($artist->plays_count) }} {{ Str::plural('play', $artist->plays_count) }}
                                    </p>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
