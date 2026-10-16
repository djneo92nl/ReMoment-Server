<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$artist->name"
                       :subtitle="number_format($totalPlays).' '.Str::plural('play', $totalPlays).($totalSeconds > 0 ? ' · '.\App\Domain\Helpers\TimeHelper::humanDuration((int) $totalSeconds).' listened' : '')"
                       :back="route('artists.index')">
            <x-slot name="badge">
                <form method="POST" action="{{ route('artists.favorite', $artist) }}">
                    @csrf
                    <button type="submit"
                            title="{{ $artist->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}"
                            class="text-xl transition-colors {{ $artist->favorited_at ? 'text-rose-500 hover:text-rose-600' : 'text-gray-300 hover:text-rose-400 dark:text-stone-600' }}">
                        <i class="{{ $artist->favorited_at ? 'fa-solid' : 'fa-regular' }} fa-heart"></i>
                    </button>
                </form>
            </x-slot>
            @auth
                @if(! \App\Domain\Library\LibraryRemover::ownedElsewhere($artist->tracks()))
                    <form method="POST" action="{{ route('artists.destroy', $artist) }}" class="mt-2" onsubmit="return confirm('Remove this artist and all their albums and tracks from the library? Your play history is kept.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs text-gray-400 hover:text-red-500 transition-colors">
                            <i class="fa-solid fa-trash-can mr-1"></i>Remove from library
                        </button>
                    </form>
                @endif
            @endauth
        </x-page-header>
    </x-slot>

    @php
        $coverAlbum = $artist->coverAlbum();
        $heroColors = $coverAlbum?->colors ?? [];
        $genres = $artist->genres();
        $country = $artist->country();
        $details = $artist->details();
        $bio = $details['bio'];
        $links = (array) $details['links'];
        $artistImage = $artist->photoUrl();
        $fanart = \App\Domain\Artwork\LibraryItemArtwork::webUrl(\App\Domain\Artwork\LibraryItemArtwork::fanart($artist->fanartUrl())['proxy_bg'] ?? null);
        $gallery = collect($artist->galleryUrls())->map(fn ($url) => ['url' => $url, 'thumb' => \App\Domain\Artwork\LibraryItemArtwork::proxy($url, 320)])->filter(fn ($g) => $g['thumb'])->values();
        $extraTags = collect([$details['mood'], $details['style'], ...$details['tags']])->filter()
            ->reject(fn ($tag) => collect($genres)->contains(fn ($g) => strcasecmp($g, $tag) === 0))->unique()->take(8);
        $lifeSpan = $details['begin_date'] ? ($details['end_date'] ? substr($details['begin_date'], 0, 4).' – '.substr($details['end_date'], 0, 4) : 'Since '.substr($details['begin_date'], 0, 4)) : null;
        $similarArtists = $artist->similarArtistModels();
    @endphp

    <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-sm border border-gray-200/70 dark:border-stone-800 p-6 md:p-8 mb-6"
         @if($fanart) style="background: linear-gradient(rgba(0,0,0,.35), rgba(0,0,0,.35)), url('{{ $fanart }}') center/cover" @elseif(count($heroColors) >= 2) style="background: linear-gradient(135deg, {{ $heroColors[0] }}22, {{ $heroColors[1] }}11)" @endif>
        <div class="flex flex-col md:flex-row items-center md:items-start gap-6 text-center md:text-left">
            <x-artwork-thumb
                :src="$artistImage ?? $coverAlbum?->images[0]['url'] ?? null"
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
                        <x-badge>{{ $genre }}</x-badge>
                    @endforeach
                    @if($country)
                        <x-badge>
                            <i class="fa-solid fa-earth-americas mr-1"></i>{{ $details['area'] ? $details['area'].', ' : '' }}{{ $country }}
                        </x-badge>
                    @endif
                    @if($details['type'] || $lifeSpan)
                        <x-badge>
                            {{ collect([ucfirst((string) $details['type']), $lifeSpan])->filter()->implode(' · ') }}
                        </x-badge>
                    @endif
                </div>

                @if($extraTags->isNotEmpty())
                    <div class="flex flex-wrap items-center justify-center md:justify-start gap-1.5 mt-2">
                        @foreach($extraTags as $tag)
                            <x-badge variant="outline">{{ $tag }}</x-badge>
                        @endforeach
                    </div>
                @endif

                @if($bio)
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400 leading-relaxed max-w-2xl">{{ $bio }}</p>
                @endif

                @if($details['listeners'])
                    <p class="mt-3 text-xs text-gray-400 dark:text-gray-600">
                        {{ number_format($details['listeners']) }} listeners · {{ number_format($details['playcount']) }} scrobbles on Last.fm
                    </p>
                @endif

                @if($details['wikipedia_url'] || $links)
                    <div class="flex flex-wrap items-center justify-center md:justify-start gap-x-4 gap-y-1 mt-3 text-xs">
                        @if($details['wikipedia_url'])
                            <a href="{{ $details['wikipedia_url'] }}" target="_blank" rel="noopener" class="text-gray-900 dark:text-gray-100 underline">Wikipedia</a>
                        @endif
                        @foreach(['official' => 'Website', 'discogs' => 'Discogs', 'bandcamp' => 'Bandcamp', 'allmusic' => 'AllMusic', 'lastfm' => 'Last.fm', 'instagram' => 'Instagram'] as $linkKey => $linkLabel)
                            @isset($links[$linkKey])
                                <a href="{{ $links[$linkKey] }}" target="_blank" rel="noopener" class="text-gray-900 dark:text-gray-100 underline">{{ $linkLabel }}</a>
                            @endisset
                        @endforeach
                    </div>
                @endif

                @if($playableDevices->isNotEmpty())
                    <div class="flex items-center justify-center md:justify-start gap-2 mt-4">
                        <x-play-button
                            name="play-artist-{{ $artist->id }}"
                            title="Play all tracks"
                            :description="$artist->name"
                            :devices="$playableDevices"
                            :action-template="\App\Support\DeviceAction::template('artists.play', ['artist' => $artist])"
                            class="flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-gray-900 dark:bg-stone-700 hover:bg-gray-700 dark:hover:bg-stone-600 text-white text-sm font-medium transition-colors">
                            <i class="fa-solid fa-play text-xs"></i>
                            Play all
                        </x-play-button>
                        <x-play-button
                            name="shuffle-artist-{{ $artist->id }}"
                            title="Shuffle all tracks"
                            :description="$artist->name"
                            :devices="$playableDevices"
                            :action-template="\App\Support\DeviceAction::template('artists.play', ['artist' => $artist, 'shuffle' => 1])"
                            :elsewhere="false"
                            class="flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-700 dark:text-gray-300 text-sm font-medium transition-colors">
                            <i class="fa-solid fa-shuffle text-xs"></i>
                            Shuffle
                        </x-play-button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if($gallery->count() > 1)
        <div class="flex gap-3 overflow-x-auto mb-6 pb-1">
            @foreach($gallery as $image)
                <a href="{{ $image['url'] }}" target="_blank" rel="noopener" class="shrink-0">
                    <img src="{{ $image['thumb'] }}" alt="{{ $artist->name }}" loading="lazy" class="h-32 w-56 object-cover rounded-2xl shadow ring-1 ring-black/5">
                </a>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        <!-- Left: Top tracks + Albums -->
        <div class="lg:col-span-2 space-y-6">

            @if($topTracks->isNotEmpty())
                <x-card class="p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Top Tracks</h2>
                    <div class="space-y-1">
                        @foreach($topTracks as $i => $track)
                            @php
                                $artUrl = \App\Domain\Artwork\LibraryItemArtwork::proxy(\App\Domain\Artwork\LibraryArtwork::coverUrl($track->images) ?? \App\Domain\Artwork\LibraryArtwork::coverUrl($track->album?->images), 120);
                            @endphp
                            <div class="flex items-center gap-4 p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-stone-800/50 transition-colors group">
                                <span class="w-5 text-center text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">{{ $i + 1 }}</span>
                                <div class="w-9 h-9 rounded-xl overflow-hidden bg-gray-100 dark:bg-stone-800 flex-shrink-0">
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
                </x-card>
            @endif

            @php
                // An album from the DLNA library has at least one track with a stream URL; the rest were added from Spotify.
                $isLocal = fn ($a) => $a->tracks->contains(fn ($t) => $t->getDlnaUrl());
                $localAlbums = $artist->albums->filter($isLocal)->count();
                $addedAlbums = $artist->albums->count() - $localAlbums;
            @endphp
            @if($artist->albums->isNotEmpty())
                <x-card class="p-6 md:p-8" x-data="{ localOnly: false }">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                        <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Albums</h2>
                        <div class="flex items-center gap-2">
                            @if($localAlbums > 0 && $addedAlbums > 0)
                                <button type="button" @click="localOnly = !localOnly"
                                        class="flex items-center gap-1.5 px-3 py-1 rounded-full bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-xs text-gray-600 dark:text-gray-400 transition-colors">
                                    <i class="fa-solid" :class="localOnly ? 'fa-eye' : 'fa-eye-slash'"></i>
                                    <span x-text="localOnly ? 'Show all albums' : 'Only my library'"></span>
                                </button>
                            @endif
                        </div>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        @foreach($artist->albums as $album)
                            <a href="{{ route('albums.show', $album) }}"
                               @if($localAlbums > 0 && !$isLocal($album)) x-show="!localOnly" @endif
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
                </x-card>
            @endif

            @if($spotifyConnected)
                <livewire:artist-more-on-spotify :artist="$artist" :key="'spotify-more-'.$artist->id" />
            @endif
        </div>

        <!-- Right: Recent plays -->
        <div>
            @if($recentPlays->isNotEmpty())
                <x-card class="p-6 md:p-8">
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
                </x-card>
            @endif

            @if($similarArtists->isNotEmpty())
                <x-card class="p-6 md:p-8 mt-6">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Similar Artists</h2>
                    <div class="flex flex-wrap gap-2">
                        @foreach($similarArtists as $similarArtist)
                            <x-chip :href="route('artists.show', $similarArtist)">{{ $similarArtist->name }}</x-chip>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
