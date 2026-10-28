<div class="relative max-w-xl" x-data="{ open: false }" @click.outside="open = false">
    <div class="relative">
        <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-gray-300 dark:text-stone-600"></i>
        <x-text-input type="text" wire:model.live.debounce.300ms="query"
               @focus="open = true" @input="open = true"
               placeholder="Search artists, albums, tracks, playlists&hellip;"
               class="w-full pl-10 pr-9" />
        @if($query !== '')
            <button wire:click="clear" @click="open = false"
                    class="absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-300 dark:text-stone-600 hover:text-gray-500 dark:hover:text-gray-400">
                <i class="fa-solid fa-xmark text-xs"></i>
            </button>
        @endif
    </div>

    @if($notice)
        <p class="mt-2 text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</p>
    @endif

    @if(mb_strlen(trim($query)) >= 2)
        <div x-show="open" x-transition @click="open = false"
             class="absolute left-0 right-0 mt-2 z-30 bg-white dark:bg-stone-900 rounded-2xl shadow-xl border border-gray-200/70 dark:border-stone-700/80 py-2 max-h-96 overflow-y-auto">
            @php($spotifyHas = $spotify && (count($spotify['artists']) > 0 || count($spotify['albums']) > 0 || count($spotify['tracks']) > 0))
            @if($artists->isEmpty() && $albums->isEmpty() && $tracks->isEmpty() && $playlists->isEmpty() && !$spotifyHas)
                <p class="px-4 py-3 text-sm text-gray-400 dark:text-gray-600">No results found.</p>
            @else
                @if($artists->isNotEmpty())
                    <p class="px-4 pt-2 pb-1 text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Artists</p>
                    @foreach($artists as $artist)
                        <a href="{{ route('artists.show', $artist) }}" class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 dark:hover:bg-stone-800 transition-colors">
                            <i class="fa-solid fa-microphone-lines text-gray-400 dark:text-gray-500 w-4 text-xs"></i>
                            <span class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ $artist->name }}</span>
                        </a>
                    @endforeach
                @endif

                @if($albums->isNotEmpty())
                    <p class="px-4 pt-2 pb-1 text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Albums</p>
                    @foreach($albums as $album)
                        <a href="{{ route('albums.show', $album) }}" class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 dark:hover:bg-stone-800 transition-colors">
                            <i class="fa-solid fa-compact-disc text-gray-400 dark:text-gray-500 w-4 text-xs"></i>
                            <span class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ $album->name }}</span>
                            <span class="text-xs text-gray-400 dark:text-gray-600 truncate">{{ $album->artist?->name }}</span>
                        </a>
                    @endforeach
                @endif

                @if($tracks->isNotEmpty())
                    <p class="px-4 pt-2 pb-1 text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Tracks</p>
                    @foreach($tracks as $track)
                        <a href="{{ $track->album_id ? route('albums.show', $track->album_id) : '#' }}" class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 dark:hover:bg-stone-800 transition-colors">
                            <x-source-icon :source="$track->source" class="w-4" />
                            <span class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ $track->name }}</span>
                            <span class="text-xs text-gray-400 dark:text-gray-600 truncate">{{ $track->artist?->name }}</span>
                        </a>
                    @endforeach
                @endif

                @if($playlists->isNotEmpty())
                    <p class="px-4 pt-2 pb-1 text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Playlists</p>
                    @foreach($playlists as $playlist)
                        <a href="{{ route('playlists.show', $playlist) }}" class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 dark:hover:bg-stone-800 transition-colors">
                            <i class="fa-solid fa-list-ul text-gray-400 dark:text-gray-500 w-4 text-xs"></i>
                            <span class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ $playlist->name }}</span>
                        </a>
                    @endforeach
                @endif

                @if($spotifyHas)
                    <p class="px-4 pt-3 pb-1 text-[10px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 border-t border-gray-100 dark:border-stone-800 mt-2">
                        <i class="fa-brands fa-spotify text-emerald-500 mr-1"></i>From Spotify
                    </p>
                    @foreach($spotify['artists'] as $sArtist)
                        <div class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 dark:hover:bg-stone-800 transition-colors">
                            <div style="width:28px;height:28px;flex:none"><x-artwork-thumb :src="$sArtist['image']" :seed="$sArtist['name']" icon="fa-solid fa-microphone-lines" size="w-full h-full" rounded="rounded-xl" class="text-[10px]" /></div>
                            <a href="{{ route('external.artist', ['provider' => 'spotify', 'id' => $sArtist['id']]) }}" class="text-sm text-gray-800 dark:text-gray-200 truncate flex-1 min-w-0 hover:underline" title="Browse on Spotify">{{ $sArtist['name'] }}</a>
                            <button type="button" wire:click="playExternal('artist', '{{ $sArtist['id'] }}')" @click.stop aria-label="Play {{ $sArtist['name'] }} from Spotify" title="Play from Spotify on the pinned player"
                                    class="flex-shrink-0 text-xs text-gray-900 dark:text-gray-100"><i class="fa-solid fa-play"></i></button>
                            @if($sArtist['local'])
                                <a href="{{ route('artists.show', $sArtist['local']) }}" class="text-xs text-gray-900 dark:text-gray-100 underline flex-shrink-0">In library</a>
                            @endif
                        </div>
                    @endforeach
                    @foreach($spotify['albums'] as $sAlbum)
                        <div class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 dark:hover:bg-stone-800 transition-colors">
                            <div style="width:28px;height:28px;flex:none"><x-artwork-thumb :src="$sAlbum['image']" :seed="$sAlbum['name']" icon="fa-solid fa-compact-disc" size="w-full h-full" rounded="rounded-xl" class="text-[10px]" /></div>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('external.album', ['provider' => 'spotify', 'id' => $sAlbum['id']]) }}" class="block text-sm text-gray-800 dark:text-gray-200 truncate hover:underline" title="See the tracks on Spotify">{{ $sAlbum['name'] }}</a>
                                <p class="text-xs text-gray-400 dark:text-gray-600 truncate">{{ $sAlbum['artist'] }}{{ $sAlbum['year'] ? ' · '.$sAlbum['year'] : '' }}</p>
                            </div>
                            <button type="button" wire:click="playExternal('album', '{{ $sAlbum['id'] }}')" @click.stop aria-label="Play {{ $sAlbum['name'] }} from Spotify" title="Play from Spotify on the pinned player"
                                    class="flex-shrink-0 text-xs text-gray-900 dark:text-gray-100"><i class="fa-solid fa-play"></i></button>
                            @if($sAlbum['local'])
                                <a href="{{ route('albums.show', $sAlbum['local']) }}" class="text-xs text-gray-900 dark:text-gray-100 underline flex-shrink-0">In library</a>
                            @endif
                        </div>
                    @endforeach
                    @foreach($spotify['tracks'] as $sTrack)
                        <div class="flex items-center gap-3 px-4 py-2 hover:bg-gray-50 dark:hover:bg-stone-800 transition-colors" data-test="spotify-track-{{ $sTrack['id'] }}">
                            <div style="width:28px;height:28px;flex:none"><x-artwork-thumb :src="$sTrack['image']" :seed="$sTrack['name']" icon="fa-solid fa-music" size="w-full h-full" rounded="rounded-xl" /></div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ $sTrack['name'] }}</p>
                                <p class="text-xs text-gray-400 dark:text-gray-600 truncate">{{ $sTrack['artist'] }}{{ $sTrack['album'] ? ' · '.$sTrack['album'] : '' }}</p>
                            </div>
                            <button type="button" wire:click="playExternal('track', '{{ $sTrack['id'] }}')" @click.stop aria-label="Play {{ $sTrack['name'] }} from Spotify" title="Play from Spotify on the pinned player"
                                    class="flex-shrink-0 text-xs text-gray-900 dark:text-gray-100"><i class="fa-solid fa-play"></i></button>
                            @if($sTrack['local'])
                                <span class="text-xs text-gray-500 flex-shrink-0">In library</span>
                            @endif
                            <button type="button" wire:click="toggleWant(@js(['title' => $sTrack['name'], 'artist' => $sTrack['artist'], 'album' => $sTrack['album'], 'image' => $sTrack['image']]))" @click.stop data-test="want-{{ $sTrack['id'] }}"
                                    aria-label="{{ $sTrack['wished'] ? 'Take off the wishlist' : 'Add to the wishlist' }}" title="{{ $sTrack['wished'] ? 'On the wishlist' : 'Want to buy or rip this song' }}"
                                    class="flex-shrink-0 text-xs {{ $sTrack['wished'] ? 'text-amber-500' : 'text-gray-400 dark:text-stone-500' }}"><i class="fa-{{ $sTrack['wished'] ? 'solid' : 'regular' }} fa-star"></i></button>
                        </div>
                    @endforeach
                @endif
            @endif
        </div>
    @endif
</div>
