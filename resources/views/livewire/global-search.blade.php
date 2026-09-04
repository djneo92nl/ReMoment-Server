<div class="relative max-w-xl" x-data="{ open: false }" @click.outside="open = false">
    <div class="relative">
        <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-gray-300 dark:text-stone-600"></i>
        <input type="text" wire:model.live.debounce.300ms="query"
               @focus="open = true" @input="open = true"
               placeholder="Search artists, albums, tracks, playlists&hellip;"
               class="w-full pl-10 pr-9 py-2.5 text-sm rounded-2xl border-gray-200 dark:border-stone-700 dark:bg-stone-900 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500">
        @if($query !== '')
            <button wire:click="clear" @click="open = false"
                    class="absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-300 dark:text-stone-600 hover:text-gray-500 dark:hover:text-gray-400">
                <i class="fa-solid fa-xmark text-xs"></i>
            </button>
        @endif
    </div>

    @if(mb_strlen(trim($query)) >= 2)
        <div x-show="open" x-transition @click="open = false"
             class="absolute left-0 right-0 mt-2 z-30 bg-white dark:bg-stone-900 rounded-2xl shadow-xl border border-gray-200/70 dark:border-stone-700/80 py-2 max-h-96 overflow-y-auto">
            @if($artists->isEmpty() && $albums->isEmpty() && $tracks->isEmpty() && $playlists->isEmpty())
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
            @endif
        </div>
    @endif
</div>
