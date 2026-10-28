<x-app-layout>
    @php
        $chips = [
            ['Albums', 'compact-disc', route('albums.index'), $counts['albums']],
            ['Artists', 'microphone-lines', route('artists.index'), $counts['artists']],
            ['Tracks', 'music', route('library.tracks'), $counts['tracks']],
            ['Playlists', 'list-ul', route('playlists.index'), $counts['playlists']],
            ['Genres', 'tags', route('genres.index'), $counts['genres']],
            ['Radio', 'radio', route('radio.index'), $counts['radio']],
            ['Favorites', 'heart', route('library.favorites'), $counts['favorites']],
        ];
    @endphp

    <div class="space-y-9">
        <x-library-hero :hero="$hero" :scope="$scope" />

        {{-- Where to go: the other library pages --}}
        <nav class="no-scrollbar -mx-5 flex gap-2.5 overflow-x-auto px-5 sm:-mx-8 sm:px-8 lg:-mx-12 lg:px-12" aria-label="Library sections">
            @foreach($chips as [$label, $icon, $href, $count])
                <x-chip :href="$href" class="shrink-0 py-2">
                    <i class="fa-solid fa-{{ $icon }} text-xs text-gray-400 dark:text-gray-500"></i>{{ $label }}
                    @if($count > 0)<span class="text-xs text-gray-400 dark:text-gray-600">{{ number_format($count) }}</span>@endif
                </x-chip>
            @endforeach
        </nav>

        {{-- Listening modes: a random selection of analysed tracks that fit, on the player pinned in this browser --}}
        @if($listeningModes)
            <section>
                <h2 class="mb-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">Listening modes</h2>
                <div class="flex flex-wrap gap-2.5">
                    @foreach($listeningModes as $mode)
                        <form method="POST" action="{{ route('library.listening-modes.play', $mode['key']) }}">
                            @csrf
                            <button type="submit" title="{{ $mode['description'] }}"
                                    class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white dark:bg-stone-900 border border-gray-200/70 dark:border-stone-800 text-sm text-gray-700 dark:text-gray-300 hover:border-gray-300 dark:hover:border-stone-600 transition-colors">
                                <i class="fa-solid fa-{{ $mode['icon'] }} text-xs text-gray-400 dark:text-gray-500"></i>{{ $mode['name'] }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- What was played last: albums, playlists and radio stations together --}}
        @if($recentPlayed->isNotEmpty())
            <x-shelf title="Recently played" :href="route('history.index')">
                @foreach($recentPlayed as $item)
                    @if($item['album'])
                        <x-album-card :album="$item['album']" tinted />
                    @elseif($item['playlist'])
                        @php
                            $choice = $playedArtwork[$item['playlist']->id] ?? null;
                            $art = $choice ? \App\Domain\Artwork\LibraryItemArtwork::forUrl($choice['url'], $choice['sources']) : null;
                        @endphp
                        <x-media-card :href="route('playlists.show', $item['playlist'])" :src="$art['proxy_320'] ?? null" :colors="$art['colors'] ?? null"
                                      :title="$item['playlist']->name" :subtitle="'Playlist · '.number_format($item['playlist']->tracks_count).' '.Str::plural('track', $item['playlist']->tracks_count)"
                                      icon="fa-solid fa-list-ul" :play="\App\Support\DeviceAction::template('playlists.play', ['playlist' => $item['playlist']])" />
                    @else
                        @php $art = \App\Domain\Artwork\LibraryItemArtwork::forUrl($item['station']->image_url); @endphp
                        <x-media-card :href="route('radio.index')" :src="$art['proxy_320'] ?? null" :colors="$art['colors'] ?? null"
                                      :title="$item['station']->name" subtitle="Radio" icon="fa-solid fa-radio"
                                      :play="\App\Support\DeviceAction::template('radio.play', ['radio' => $item['station']])" />
                    @endif
                @endforeach
            </x-shelf>
        @endif

        @if($recentAdded->isNotEmpty())
            <x-shelf title="Recently added" :href="route('albums.index', ['sort' => 'recent'])">
                @foreach($recentAdded as $album)
                    <x-album-card :album="$album" tinted />
                @endforeach
            </x-shelf>
        @else
            <x-empty-state icon="compact-disc" text="No albums yet — start listening to build your library" />
        @endif

        @if($topArtists->isNotEmpty())
            <x-shelf title="Most played artists" :href="route('artists.index', ['sort' => 'plays'])">
                @foreach($topArtists as $artist)
                    <x-artist-card :artist="$artist" round />
                @endforeach
            </x-shelf>
        @endif

        @if(!empty($wishes) || !empty($wishedSongs))
            <x-shelf title="Wishlist" :href="route('library.wishlist')">
                @foreach($wishes as $wish)
                    <x-media-card :href="route('artists.show', $wish['artist']['id'])" :src="$wish['artwork']['proxy_320'] ?? null" :colors="$wish['artwork']['colors'] ?? null"
                                  :title="$wish['title']" :subtitle="$wish['artist']['name'].($wish['year'] ? ' · '.$wish['year'] : '')"
                                  icon="fa-solid fa-compact-disc" />
                @endforeach
                @foreach($wishedSongs as $song)
                    <x-media-card :href="route('library.wishlist')" :src="$song['artwork']['proxy_320'] ?? null" :colors="$song['artwork']['colors'] ?? null"
                                  :title="$song['title']" :subtitle="$song['artist']" icon="fa-solid fa-music" />
                @endforeach
            </x-shelf>
        @endif

        @if($playlists->isNotEmpty())
            <x-shelf title="Playlists" :href="route('playlists.index')">
                @foreach($playlists as $playlist)
                    @php
                        $choice = $playlistArtwork[$playlist->id] ?? null;
                        $art = $choice ? \App\Domain\Artwork\LibraryItemArtwork::forUrl($choice['url'], $choice['sources']) : null;
                    @endphp
                    <x-media-card :href="route('playlists.show', $playlist)" :src="$art['proxy_320'] ?? null" :colors="$art['colors'] ?? null"
                                  :title="$playlist->name" :subtitle="number_format($playlist->tracks_count).' '.Str::plural('track', $playlist->tracks_count)"
                                  icon="fa-solid fa-list-ul" :play="\App\Support\DeviceAction::template('playlists.play', ['playlist' => $playlist])" />
                @endforeach
            </x-shelf>
        @endif

        @if($genres->isNotEmpty())
            <x-shelf title="Genres" :href="route('genres.index')">
                @foreach($genres as $genre)
                    <x-genre-tile :genre="$genre" :albums="$genreAlbums[$genre->id] ?? null" />
                @endforeach
            </x-shelf>
        @endif

        @if($favoriteAlbums->isNotEmpty())
            <x-shelf title="Favorite albums" :href="route('library.favorites')">
                @foreach($favoriteAlbums as $album)
                    <x-album-card :album="$album" tinted />
                @endforeach
            </x-shelf>
        @endif

        @if($favoriteArtists->isNotEmpty())
            <x-shelf title="Favorite artists" :href="route('library.favorites')">
                @foreach($favoriteArtists as $artist)
                    <x-artist-card :artist="$artist" round />
                @endforeach
            </x-shelf>
        @endif

        {{-- Discover: what the enrichment says about what you could play next --}}
        @if($similarArtists->isNotEmpty())
            <x-shelf title="Similar to what you play most">
                @foreach($similarArtists as $artist)
                    <x-artist-card :artist="$artist" round />
                @endforeach
            </x-shelf>
        @endif

        @if($topRated->isNotEmpty())
            <x-shelf title="Highest rated" :href="route('albums.index', ['sort' => 'rating'])">
                @foreach($topRated as $album)
                    <x-album-card :album="$album" tinted detail />
                @endforeach
            </x-shelf>
        @endif

        @if($sound['moods'] || $sound['tempos'])
            <div class="grid gap-7 md:grid-cols-2">
                @if($sound['moods'])
                    <section>
                        <h2 class="mb-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">By mood</h2>
                        <div class="flex flex-wrap gap-2.5">
                            @foreach($sound['moods'] as $mood => $count)
                                <x-chip :href="route('albums.index', ['mood' => $mood])">{{ ucfirst($mood) }} <span class="text-xs text-gray-400 dark:text-gray-600">{{ $count }}</span></x-chip>
                            @endforeach
                        </div>
                    </section>
                @endif
                @if($sound['tempos'])
                    <section>
                        <h2 class="mb-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">By tempo</h2>
                        <div class="flex flex-wrap gap-2.5">
                            @foreach(['slow' => 'Slow · under 90 BPM', 'steady' => 'Steady · 90–125', 'fast' => 'Fast · over 125'] as $key => $label)
                                @if(isset($sound['tempos'][$key]))
                                    <x-chip :href="route('albums.index', ['tempo' => $key])">{{ $label }} <span class="text-xs text-gray-400 dark:text-gray-600">{{ $sound['tempos'][$key] }}</span></x-chip>
                                @endif
                            @endforeach
                        </div>
                    </section>
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
    </div>
</x-app-layout>
