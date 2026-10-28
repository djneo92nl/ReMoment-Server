<x-app-layout>
    @php
        $pinned = app(\App\Support\SelectedDevice::class)->device();
        $details = $artist->details();

        $sourceNames = collect($sources)->mapWithKeys(fn ($s) => [$s => \App\Domain\Library\LibrarySources::LIBRARIES[$s]['name']]);

        $tabs = array_filter([
            'overview' => ['Overview', 'house'],
            'about' => $details['bio'] ? ['About', 'circle-info'] : null,
            'plays' => $recentPlays->isNotEmpty() ? ['Recent plays', 'clock-rotate-left'] : null,
        ]);
        $panel = 'rounded-3xl border border-gray-200/70 bg-white p-5 shadow-sm sm:p-6 dark:border-stone-800 dark:bg-stone-900';
        $rowBtn = 'flex h-8 w-8 items-center justify-center rounded-full';
    @endphp

    <div class="space-y-8">
        <x-artist-hero :artist="$artist" :devices="$playableDevices" :total-plays="$totalPlays" :total-seconds="$totalSeconds" :album-count="$albums->count()" :track-count="$trackCount" :source="$source" />

        {{-- Source: streaming is never merged with local; with several, this picks what the albums, the popular tracks and Play show --}}
        @if(count($sources) > 1)
            <nav class="flex gap-1 overflow-x-auto" aria-label="Source">
                @foreach($sourceNames as $key => $name)
                    <a href="{{ route('artists.show', [$artist, 'source' => $key]) }}" data-test="source-{{ $key }}" @if($source === $key) aria-current="page" @endif
                       class="inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium transition-colors {{ $source === $key ? 'bg-gray-100 text-gray-900 dark:bg-stone-800 dark:text-gray-100' : 'text-gray-500 hover:text-gray-800 dark:hover:text-gray-200' }}">
                        <x-source-icon :source="$key" />{{ $name }}
                    </a>
                @endforeach
            </nav>
        @elseif($source)
            <p class="flex items-center gap-2 text-sm text-gray-500"><x-source-icon :source="$source" />In {{ $sourceNames[$source] }}</p>
        @endif

        <x-tabs :tabs="$tabs">
            <x-tabs.panel name="overview" class="space-y-8">

                @if($source === 'spotify')
                    {{-- The Spotify side is the service's own view: all its albums and the popular tracks, not the library's copies of them. --}}
                    @if($spotifyArtistId)
                        <livewire:external-artist :artist-id="$spotifyArtistId" embedded :key="'spotify-artist-'.$spotifyArtistId" />
                    @else
                        <p class="text-sm text-gray-400 dark:text-gray-600">Spotify does not know an artist called {{ $artist->name }}.</p>
                    @endif
                @else
                @if($topTracks->isNotEmpty())
                    <section>
                        <h2 class="mb-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">Popular</h2>
                        <x-card flush>
                            <div class="divide-y divide-gray-100 dark:divide-stone-800/70">
                                @foreach($topTracks as $i => $track)
                                    @php
                                        $artUrl = \App\Domain\Artwork\LibraryItemArtwork::proxy(\App\Domain\Artwork\LibraryArtwork::coverUrl($track->images) ?? \App\Domain\Artwork\LibraryArtwork::coverUrl($track->album?->images), 120);
                                        $canPlayHere = \App\Domain\Library\LibraryPlayback::trackPlayable($track, $pinned) && ($pinned !== null || $playableDevices->isNotEmpty());
                                        $playAction = \App\Support\DeviceAction::template('tracks.play', ['track' => $track]);
                                    @endphp
                                    <div class="group flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-gray-50 sm:px-5 dark:hover:bg-stone-800/30">
                                        {{-- The rank; the play button takes its place on hover or focus, and on touch screens --}}
                                        <div class="relative h-8 w-8 flex-none">
                                            <span class="absolute inset-0 flex items-center justify-center text-sm tabular-nums text-gray-400 dark:text-stone-500 {{ $canPlayHere ? 'group-hover:hidden group-focus-within:hidden [@media(hover:none)]:hidden' : '' }}">{{ $i + 1 }}</span>
                                            @if($canPlayHere)
                                                @if($pinned)
                                                    <form method="POST" action="{{ str_replace('{id}', $pinned->id, $playAction) }}" class="hidden group-hover:block group-focus-within:block [@media(hover:none)]:block">
                                                        @csrf
                                                        <button type="submit" class="{{ $rowBtn }} text-gray-900 hover:bg-gray-200 dark:text-gray-100 dark:hover:bg-stone-700" title="Play on {{ $pinned->device_name }}" aria-label="Play {{ $track->name }} on {{ $pinned->device_name }}"><i class="fa-solid fa-play text-xs"></i></button>
                                                    </form>
                                                @else
                                                    <button type="button" class="{{ $rowBtn }} hidden text-gray-900 hover:bg-gray-200 group-hover:flex group-focus-within:flex dark:text-gray-100 dark:hover:bg-stone-700 [@media(hover:none)]:flex" @click="$dispatch('open-player-panel')" title="Choose a player to play on" aria-label="Choose a player to play {{ $track->name }} on"><i class="fa-solid fa-play text-xs"></i></button>
                                                @endif
                                            @endif
                                        </div>
                                        <div class="h-10 w-10 flex-none overflow-hidden rounded-xl bg-gray-100 dark:bg-stone-800">
                                            @if($artUrl)
                                                <img src="{{ $artUrl }}" alt="" loading="lazy" class="h-full w-full object-cover">
                                            @else
                                                <div class="flex h-full w-full items-center justify-center"><i class="fa-solid fa-music text-xs text-gray-300 dark:text-stone-600"></i></div>
                                            @endif
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm text-gray-900 dark:text-gray-100">{{ $track->name }}</p>
                                            @if($track->album)
                                                <p class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-600">
                                                    <a href="{{ route('albums.show', $track->album) }}" class="hover:underline">{{ $track->album->name }}</a>
                                                </p>
                                            @endif
                                        </div>
                                        <span class="flex-none text-xs tabular-nums text-gray-400 dark:text-stone-500">{{ number_format($track->plays_count) }} {{ Str::plural('play', $track->plays_count) }}</span>
                                        @if($pinned)
                                            <x-menu label="Options"><x-queue-menu-items type="track" :id="$track->id" :label="$track->name" /></x-menu>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </x-card>
                    </section>
                @endif

                @if($albums->count() > 12)
                    <x-discography :albums="$albums" :source="$source" />
                @elseif($albums->isNotEmpty())
                    <x-shelf title="Albums">
                        @foreach($albums as $album)
                            <x-album-card :album="$album" :source="$source" tinted />
                        @endforeach
                    </x-shelf>
                @endif
                @endif

                @if(\App\Models\Media\CatalogueAlbum::where('artist_id', $artist->id)->exists())
                    <livewire:catalogue :artist-id="$artist->id" :key="'catalogue-'.$artist->id" />
                @endif

                @if($similarArtists->isNotEmpty())
                    <x-shelf title="Similar artists">
                        @foreach($similarArtists as $similarArtist)
                            <x-artist-card :artist="$similarArtist" round />
                        @endforeach
                    </x-shelf>
                @endif

            </x-tabs.panel>

            @isset($tabs['about'])
                <x-tabs.panel name="about" class="{{ $panel }}">
                    <p class="max-w-3xl text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ $details['bio'] }}
                        @if($details['wikipedia_url'])
                            <a href="{{ $details['wikipedia_url'] }}" target="_blank" rel="noopener" class="text-gray-900 underline dark:text-gray-100">Wikipedia</a>
                        @endif
                    </p>
                </x-tabs.panel>
            @endisset

            @isset($tabs['plays'])
                <x-tabs.panel name="plays" class="{{ $panel }}">
                    <div class="grid gap-x-8 gap-y-3 md:grid-cols-2">
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
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-gray-800 dark:text-gray-200">{{ $play->track->name }}</p>
                                    <p class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-600">
                                        {{ $play->played_at->format('M j, H:i') }}
                                        @if($play->device) &middot; {{ $play->device->device_name }} @endif
                                        @if($playSource) &middot; {{ $playSource }} @endif
                                    </p>
                                </div>
                                @if($play->skipped)
                                    <span class="flex-shrink-0 text-[10px] text-amber-500 dark:text-amber-400" title="Skipped"><i class="fa-solid fa-forward-step"></i></span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-5 border-t border-gray-100 pt-4 dark:border-stone-800">
                        <a href="{{ route('history.index') }}" class="text-xs text-gray-400 transition-colors hover:text-gray-600 dark:text-gray-600 dark:hover:text-gray-400">Full history &rarr;</a>
                    </div>
                </x-tabs.panel>
            @endisset
        </x-tabs>
    </div>
</x-app-layout>
