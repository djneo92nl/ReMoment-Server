<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Library" subtitle="What's new and what's playing">
            <x-slot name="actions"><x-library-scope :scope="$scope" /></x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-8">

        {{-- Genres of the library (canonical genres of the artists, tinted by the covers' colours) --}}
        @if($genres->isNotEmpty())
            <div>
                <x-section-heading title="Browse by Genre" :href="route('genres.index')" link="All genres →" class="mb-3" />
                <div class="flex flex-wrap gap-2.5">
                    @foreach($genres as $genre)
                        <x-chip :href="route('genres.show', $genre->slug)">
                            <span class="w-2 h-2 rounded-full flex-none" style="background:{{ $genre->colors[0] ?? 'var(--genre-fallback)' }}"></span>
                            {{ $genre->name }}
                            <span class="text-xs text-gray-400 dark:text-gray-600">{{ number_format($genre->artists) }}</span>
                        </x-chip>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Recently added albums --}}
        <div>
            <x-section-heading title="Recently Added" :href="route('albums.index', ['sort' => 'recent'])" link="All albums →" />

            @if($recentAlbums->isEmpty())
                <x-empty-state icon="compact-disc" text="No albums yet — start listening to build your library" />
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
                <x-section-heading title="Discover" class="mb-0" />

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
                                <div class="flex flex-wrap gap-2.5">
                                    @foreach($sound['moods'] as $mood => $count)
                                        <x-chip :href="route('albums.index', ['mood' => $mood])">{{ ucfirst($mood) }} <span class="text-xs text-gray-400 dark:text-gray-600">{{ $count }}</span></x-chip>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if($sound['tempos'])
                            <div>
                                <p class="text-xs text-gray-400 dark:text-gray-600 mb-3">By tempo <span class="opacity-70">(analysed own files)</span></p>
                                <div class="flex flex-wrap gap-2.5">
                                    @foreach(['slow' => 'Slow · under 90 BPM', 'steady' => 'Steady · 90–125', 'fast' => 'Fast · over 125'] as $key => $label)
                                        @if(isset($sound['tempos'][$key]))
                                            <x-chip :href="route('albums.index', ['tempo' => $key])">{{ $label }} <span class="text-xs text-gray-400 dark:text-gray-600">{{ $sound['tempos'][$key] }}</span></x-chip>
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
        <x-card class="p-6 md:p-8">
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
                                    <span class="w-10 text-gray-500">{{ $decade }}</span>
                                    <div class="flex-1 rounded-full bg-gray-100 dark:bg-stone-800" style="height:6px">
                                        <div class="rounded-full bg-gray-500 dark:bg-stone-400" style="height:6px;width:{{ max(2, round($count / $maxDecade * 100)) }}%"></div>
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
                                    <span class="text-gray-500">{{ $row['label'] }}</span>
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
        </x-card>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Recently played --}}
            <x-card class="p-6 md:p-8">
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
                                    rounded="rounded-xl"
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
            </x-card>

            {{-- Top artists --}}
            <x-card class="p-6 md:p-8">
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
                                    rounded="rounded-xl"
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
            </x-card>
        </div>
    </div>
</x-app-layout>
