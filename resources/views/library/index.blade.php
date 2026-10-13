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

        {{-- Genres of the library (canonical genres of the artists, tinted by the covers' colours) --}}
        @if($genres->isNotEmpty())
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Browse by Genre</h2>
                    <a href="{{ route('genres.index') }}" class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">All genres &rarr;</a>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:10px">
                    @foreach($genres as $genre)
                        <a href="{{ route('genres.show', $genre->slug) }}"
                           class="bg-white dark:bg-stone-900 border border-gray-200/70 dark:border-stone-800/80 text-sm text-gray-700 dark:text-gray-300 hover:border-gray-300 dark:hover:border-stone-600 transition-colors"
                           style="display:inline-flex;align-items:center;gap:8px;padding:7px 16px;border-radius:9999px">
                            <span style="width:9px;height:9px;border-radius:9999px;flex:none;background:{{ $genre->colors[0] ?? '#9ca3af' }}"></span>
                            {{ $genre->name }}
                            <span class="text-xs text-gray-400 dark:text-gray-600" style="margin-left:2px">{{ number_format($genre->artists) }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

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
                        <x-album-card :album="$album" detail />
                    @endforeach
                </x-album-grid>
            @endif
        </div>

        {{-- Discover: what the enrichment says about what you could play next --}}
        @if($similarArtists->isNotEmpty() || $sound['moods'] || $sound['tempos'] || $topRated->isNotEmpty())
            <div class="space-y-6">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Discover</h2>

                @if($similarArtists->isNotEmpty())
                    <div>
                        <p class="text-xs text-gray-400 dark:text-gray-600 mb-3">Similar to what you play most <span class="opacity-70">(Last.fm)</span></p>
                        <x-album-grid>
                            @foreach($similarArtists as $artist)
                                <x-artist-card :artist="$artist" />
                            @endforeach
                        </x-album-grid>
                    </div>
                @endif

                @if($sound['moods'] || $sound['tempos'])
                    <div class="grid gap-6 md:grid-cols-2">
                        @if($sound['moods'])
                            <div>
                                <p class="text-xs text-gray-400 dark:text-gray-600 mb-3">By mood <span class="opacity-70">(analysed own files)</span></p>
                                <div style="display:flex;flex-wrap:wrap;gap:10px">
                                    @foreach($sound['moods'] as $mood => $count)
                                        <a href="{{ route('albums.index', ['mood' => $mood]) }}" class="bg-white dark:bg-stone-900 border border-gray-200/70 dark:border-stone-800/80 text-sm text-gray-700 dark:text-gray-300 hover:border-gray-300 dark:hover:border-stone-600 transition-colors" style="display:inline-block;padding:7px 16px;border-radius:9999px">
                                            {{ ucfirst($mood) }} <span class="text-xs text-gray-400 dark:text-gray-600" style="margin-left:4px">{{ $count }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if($sound['tempos'])
                            <div>
                                <p class="text-xs text-gray-400 dark:text-gray-600 mb-3">By tempo <span class="opacity-70">(analysed own files)</span></p>
                                <div style="display:flex;flex-wrap:wrap;gap:10px">
                                    @foreach(['slow' => 'Slow · under 90 BPM', 'steady' => 'Steady · 90–125', 'fast' => 'Fast · over 125'] as $key => $label)
                                        @if(isset($sound['tempos'][$key]))
                                            <a href="{{ route('albums.index', ['tempo' => $key]) }}" class="bg-white dark:bg-stone-900 border border-gray-200/70 dark:border-stone-800/80 text-sm text-gray-700 dark:text-gray-300 hover:border-gray-300 dark:hover:border-stone-600 transition-colors" style="display:inline-block;padding:7px 16px;border-radius:9999px">
                                                {{ $label }} <span class="text-xs text-gray-400 dark:text-gray-600" style="margin-left:4px">{{ $sound['tempos'][$key] }}</span>
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                @if($topRated->isNotEmpty())
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs text-gray-400 dark:text-gray-600">Highest rated <span class="opacity-70">(TheAudioDB)</span></p>
                            <a href="{{ route('albums.index', ['sort' => 'rating']) }}" class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">More &rarr;</a>
                        </div>
                        <x-album-grid>
                            @foreach($topRated as $album)
                                <x-album-card :album="$album" detail />
                            @endforeach
                        </x-album-grid>
                    </div>
                @endif
            </div>
        @endif

        {{-- Library at a glance: totals, decades and how complete the enrichment is --}}
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
            <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Library at a Glance</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:1rem" class="mb-6">
                @foreach(['artists' => 'Artists', 'albums' => 'Albums', 'tracks' => 'Tracks'] as $key => $label)
                    <div>
                        <p class="text-2xl font-medium text-gray-900 dark:text-gray-100">{{ number_format($glance[$key]) }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-600">{{ $label }}</p>
                    </div>
                @endforeach
                <div>
                    <p class="text-2xl font-medium text-gray-900 dark:text-gray-100">{{ number_format($glance['hours']) }} h</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600">Of music</p>
                </div>
            </div>

            <div class="grid gap-8 md:grid-cols-2">
                @if($glance['decades'])
                    @php $maxDecade = max($glance['decades']); @endphp
                    <div>
                        <p class="text-xs text-gray-400 dark:text-gray-600 mb-3">Albums per decade</p>
                        <div class="space-y-1.5">
                            @foreach($glance['decades'] as $decade => $count)
                                <div class="flex items-center gap-3 text-xs">
                                    <span class="w-10 text-gray-500 dark:text-gray-500">{{ $decade }}</span>
                                    <div class="flex-1 rounded-full bg-gray-100 dark:bg-stone-800" style="height:6px">
                                        <div class="rounded-full bg-indigo-500" style="height:6px;width:{{ max(2, round($count / $maxDecade * 100)) }}%"></div>
                                    </div>
                                    <span class="w-10 text-right text-gray-400 dark:text-gray-600">{{ number_format($count) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mb-3">Enrichment</p>
                    <div class="space-y-2.5">
                        @foreach($glance['coverage'] as $row)
                            @php $percent = $row['of'] > 0 ? round($row['have'] / $row['of'] * 100) : 0; @endphp
                            <div>
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="text-gray-500 dark:text-gray-500">{{ $row['label'] }}</span>
                                    <span class="text-gray-400 dark:text-gray-600">{{ number_format($row['have']) }} / {{ number_format($row['of']) }} · {{ $percent }}%</span>
                                </div>
                                <div class="rounded-full bg-gray-100 dark:bg-stone-800" style="height:6px">
                                    <div class="rounded-full bg-emerald-500" style="height:6px;width:{{ $percent }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
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
                                    :src="$track?->images[0]['url'] ?? $track?->album?->images[0]['url'] ?? $play->image_url"
                                    :colors="$track?->album?->colors"
                                    :seed="$play->trackTitle().$play->id"
                                    icon="fa-solid fa-music"
                                    size="w-10 h-10"
                                    rounded="rounded-lg"
                                />
                                <div class="flex-1 min-w-0">
                                    @if($track?->album)
                                        <a href="{{ route('albums.show', $track->album) }}#track-{{ $track->id }}" class="block text-sm text-gray-800 dark:text-gray-200 truncate hover:underline">{{ $track->name }}</a>
                                    @else
                                        <p class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ $play->trackTitle() }}</p>
                                    @endif
                                    <p class="text-xs text-gray-400 dark:text-gray-600 truncate mt-0.5">
                                        @if($track?->artist)
                                            <a href="{{ route('artists.show', $track->artist) }}" class="hover:underline">{{ $track->artist->name }}</a>
                                        @endif
                                        @if(!$track?->artist)
                                            {{ $play->artistTitle() }}
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
