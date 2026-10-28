{{-- An album as the service has it: cover and actions, then the tracks. Same card/row patterns as the library album page. --}}
<div class="space-y-6">
    @if(!$connected)
        <x-alert type="warning">{{ $name }} is not connected. Connect it under Settings to browse its albums.</x-alert>
    @elseif($failed)
        <x-alert type="warning">{{ $name }} could not be reached. Try again in a moment.</x-alert>
    @elseif($found === null)
        <x-alert type="warning">{{ $name }} does not know this album.</x-alert>
    @else
        @php($album = $found['album'])
        <x-card class="p-5 sm:p-6">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                <div class="aspect-square w-44 flex-none overflow-hidden rounded-2xl">
                    <x-artwork-thumb :src="$album->image" :seed="$album->title" icon="fa-solid fa-compact-disc" size="w-full h-full" rounded="rounded-none" class="text-4xl" />
                </div>
                <div class="min-w-0 space-y-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600"><i class="fa-brands fa-spotify mr-1 text-emerald-500"></i>On {{ $name }}</p>
                        <h2 class="mt-1 text-2xl font-medium tracking-tight text-gray-900 dark:text-gray-100">{{ $album->title }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ $album->artist }}{{ $album->year ? ' · '.$album->year : '' }}{{ $album->trackCount ? ' · '.$album->trackCount.' tracks' : '' }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-primary-button type="button" size="md" wire:click="playExternal('album', '{{ $album->id }}')" data-test="play-album"><i class="fa-solid fa-play"></i>Play</x-primary-button>
                        <x-secondary-button type="button" size="md" wire:click="playExternal('album', '{{ $album->id }}', true)"><i class="fa-solid fa-shuffle"></i>Shuffle</x-secondary-button>
                        @if($album->libraryId)
                            <x-secondary-button size="md" :href="route('albums.show', $album->libraryId)"><i class="fa-solid fa-check"></i>In your library</x-secondary-button>
                        @endif
                    </div>
                    @if($notice)<p class="text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</p>@endif
                </div>
            </div>
        </x-card>

        <x-card flush>
            <div class="divide-y divide-gray-100 dark:divide-stone-800/70">
                @foreach($found['tracks'] as $i => $track)
                    <div class="flex items-center gap-3 px-4 py-2.5 sm:px-5" data-test="track-{{ $track->id }}">
                        <button type="button" wire:click="playExternal('track', '{{ $track->id }}')" aria-label="Play {{ $track->title }}" title="Play"
                                class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-sm text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-stone-800 dark:hover:text-gray-100">
                            <span class="tabular-nums group-hover:hidden">{{ $i + 1 }}</span>
                        </button>
                        <p class="min-w-0 flex-1 truncate text-sm text-gray-900 dark:text-gray-100">{{ $track->title }}</p>
                        @if($track->libraryId)
                            <span class="flex-none text-xs text-gray-400 dark:text-gray-600">In library</span>
                        @endif
                        <button type="button" wire:click="toggleWant(@js(['title' => $track->title, 'artist' => $track->artist ?? $album->artist, 'album' => $album->title, 'image' => $album->image]))" data-test="want-{{ $track->id }}"
                                aria-label="{{ $wished[$track->id] ? 'Take off the wishlist' : 'Add to the wishlist' }}" title="{{ $wished[$track->id] ? 'On the wishlist' : 'Want to buy or rip this song' }}"
                                class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-sm transition-colors hover:bg-gray-100 dark:hover:bg-stone-800 {{ $wished[$track->id] ? 'text-amber-500' : 'text-gray-300 dark:text-stone-600' }}">
                            <i class="fa-{{ $wished[$track->id] ? 'solid' : 'regular' }} fa-star"></i>
                        </button>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif
</div>
