<div class="space-y-6">
    @if(!$connected)
        <x-alert type="warning">{{ $name }} is not connected. Connect it under Settings to open its playlists.</x-alert>
    @elseif($failed)
        <x-alert type="warning">{{ $name }} could not be reached. Try again in a moment.</x-alert>
    @elseif($found === null)
        <x-alert type="warning">{{ $name }} does not know this playlist.</x-alert>
    @else
        @php($playlist = $found['playlist'])
        <x-card class="p-5 sm:p-6">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                <div class="aspect-square w-40 flex-none overflow-hidden rounded-2xl">
                    <x-artwork-thumb :src="$playlist->image" :seed="$playlist->title" icon="fa-solid fa-list-ul" size="w-full h-full" rounded="rounded-none" class="text-4xl" />
                </div>
                <div class="min-w-0 space-y-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600"><i class="fa-brands fa-spotify mr-1 text-emerald-500"></i>Playlist on {{ $name }}</p>
                        <h2 class="mt-1 text-2xl font-medium tracking-tight text-gray-900 dark:text-gray-100">{{ $playlist->title }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ $playlist->artist }}{{ $playlist->trackCount !== null ? ' · '.number_format($playlist->trackCount).' tracks' : '' }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-primary-button type="button" size="md" wire:click="playExternal('playlist', '{{ $playlist->id }}')" data-test="play-playlist"><i class="fa-solid fa-play"></i>Play</x-primary-button>
                        <x-secondary-button type="button" size="md" wire:click="playExternal('playlist', '{{ $playlist->id }}', true)"><i class="fa-solid fa-shuffle"></i>Shuffle</x-secondary-button>
                    </div>
                    @if($notice)<p class="text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</p>@endif
                </div>
            </div>
        </x-card>

        @if($found['tracks'] === null)
            <p class="text-sm text-gray-500">{{ $name }} only shares the tracks of playlists you own or collaborate on. This one still plays.</p>
        @else
            <x-card flush>
                <div class="divide-y divide-gray-100 dark:divide-stone-800/70" data-test="playlist-tracks">
                    @foreach($found['tracks'] as $i => $track)
                        <div class="flex items-center gap-3 px-4 py-2.5 sm:px-5" data-test="track-{{ $track->id }}">
                            <button type="button" wire:click="playExternal('track', '{{ $track->id }}')" aria-label="Play {{ $track->title }}" title="Play"
                                    class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-sm text-gray-900 hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-stone-800"><i class="fa-solid fa-play text-xs"></i></button>
                            <div class="h-10 w-10 flex-none overflow-hidden rounded-xl"><x-artwork-thumb :src="$track->image" :seed="$track->title" icon="fa-solid fa-music" size="w-full h-full" rounded="rounded-none" /></div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm text-gray-900 dark:text-gray-100">{{ $track->title }}@if($track->libraryId) <i class="fa-solid fa-circle-check ml-1 text-[11px] text-gray-400 dark:text-gray-600" title="In your library"></i>@endif</p>
                                <p class="truncate text-xs text-gray-400 dark:text-gray-600">{{ $track->artist }}</p>
                            </div>
                            <button type="button" wire:click="toggleWant(@js(['title' => $track->title, 'artist' => $track->artist, 'album' => null, 'image' => $track->image]))" data-test="want-{{ $track->id }}"
                                    aria-label="{{ $wished[$track->id] ? 'Take off the wishlist' : 'Add to the wishlist' }}" title="{{ $wished[$track->id] ? 'On the wishlist' : 'Want to buy or rip this song' }}"
                                    class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-sm transition-colors hover:bg-gray-100 dark:hover:bg-stone-800 {{ $wished[$track->id] ? 'text-amber-500' : 'text-gray-300 dark:text-stone-600' }}">
                                <i class="fa-{{ $wished[$track->id] ? 'solid' : 'regular' }} fa-star"></i>
                            </button>
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif
    @endif
</div>
