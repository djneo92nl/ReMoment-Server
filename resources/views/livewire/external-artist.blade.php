{{-- An artist as the service has it: like a local artist page (popular tracks, then every album and single), all of it playable. --}}
<div class="space-y-8">
    @if(!$connected)
        <x-alert type="warning">{{ $name }} is not connected. Connect it under Settings to browse its artists.</x-alert>
    @elseif($failed)
        <x-alert type="warning">{{ $name }} could not be reached. Try again in a moment.</x-alert>
    @elseif($found === null)
        <x-alert type="warning">{{ $name }} does not know this artist.</x-alert>
    @else
        @php($artist = $found['artist'])
        @php($albums = $found['albums'])

        @if(!$embedded)
            <x-card class="p-5 sm:p-6">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                    <div class="h-28 w-28 flex-none overflow-hidden rounded-full">
                        <x-artwork-thumb :src="$artist->image" :seed="$artist->title" icon="fa-solid fa-microphone-lines" size="w-full h-full" rounded="rounded-full" class="text-3xl" />
                    </div>
                    <div class="min-w-0 space-y-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600"><i class="fa-brands fa-spotify mr-1 text-emerald-500"></i>On {{ $name }}</p>
                            <h2 class="mt-1 text-2xl font-medium tracking-tight text-gray-900 dark:text-gray-100">{{ $artist->title }}</h2>
                            <p class="mt-1 text-sm text-gray-500">{{ count($albums) }} {{ \Illuminate\Support\Str::plural('album', count($albums)) }} and singles</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-primary-button type="button" size="md" wire:click="playExternal('artist', '{{ $artist->id }}')"><i class="fa-solid fa-play"></i>Play</x-primary-button>
                            <x-secondary-button type="button" size="md" wire:click="playExternal('artist', '{{ $artist->id }}', true)"><i class="fa-solid fa-shuffle"></i>Shuffle</x-secondary-button>
                            @if($artist->libraryId)
                                <x-secondary-button size="md" :href="route('artists.show', $artist->libraryId)"><i class="fa-solid fa-check"></i>In your library</x-secondary-button>
                            @endif
                        </div>
                        @if($notice)<p class="text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</p>@endif
                    </div>
                </div>
            </x-card>
        @else
            <div class="flex flex-wrap items-center gap-2">
                <x-primary-button type="button" size="md" wire:click="playExternal('artist', '{{ $artist->id }}')" data-test="play-artist"><i class="fa-solid fa-play"></i>Play on Spotify</x-primary-button>
                <x-secondary-button type="button" size="md" wire:click="playExternal('artist', '{{ $artist->id }}', true)"><i class="fa-solid fa-shuffle"></i>Shuffle</x-secondary-button>
                <span class="text-xs text-gray-400 dark:text-gray-600"><i class="fa-brands fa-spotify mr-1 text-emerald-500"></i>Everything {{ $name }} has for this artist</span>
                @if($notice)<span class="text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</span>@endif
            </div>
        @endif

        @if($popular !== [])
            <section>
                <h2 class="mb-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">Popular</h2>
                <x-card flush>
                    <div class="divide-y divide-gray-100 dark:divide-stone-800/70" data-test="popular">
                        @foreach($popular as $i => $track)
                            <div class="flex items-center gap-3 px-4 py-2.5 sm:px-5" data-test="popular-{{ $track->id }}">
                                <button type="button" wire:click="playExternal('track', '{{ $track->id }}')" aria-label="Play {{ $track->title }}" title="Play"
                                        class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-sm text-gray-900 hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-stone-800"><i class="fa-solid fa-play text-xs"></i></button>
                                <div class="h-10 w-10 flex-none overflow-hidden rounded-xl">
                                    <x-artwork-thumb :src="$track->image" :seed="$track->title" icon="fa-solid fa-music" size="w-full h-full" rounded="rounded-none" />
                                </div>
                                <p class="min-w-0 flex-1 truncate text-sm text-gray-900 dark:text-gray-100">{{ $track->title }}@if($track->libraryId) <i class="fa-solid fa-circle-check ml-1 text-[11px] text-gray-400 dark:text-gray-600" title="In your library"></i>@endif</p>
                                <button type="button" wire:click="toggleWant(@js($payload($track)))" data-test="want-{{ $track->id }}"
                                        aria-label="{{ $wished[$track->id] ? 'Take off the wishlist' : 'Add to the wishlist' }}" title="{{ $wished[$track->id] ? 'On the wishlist' : 'Want to buy or rip this song' }}"
                                        class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-sm transition-colors hover:bg-gray-100 dark:hover:bg-stone-800 {{ $wished[$track->id] ? 'text-amber-500' : 'text-gray-300 dark:text-stone-600' }}">
                                    <i class="fa-{{ $wished[$track->id] ? 'solid' : 'regular' }} fa-star"></i>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            </section>
        @endif

        <section>
            <h2 class="mb-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">Albums <span class="text-sm font-normal text-gray-400">· {{ count($albums) }}</span></h2>
            @if($albums === [])
                <p class="text-sm text-gray-400 dark:text-gray-600">{{ $name }} lists no albums for this artist.</p>
            @else
                <x-album-grid>
                    @foreach(array_slice($albums, 0, $limit) as $album)
                        <x-card compact flush class="min-w-0" data-test="album-{{ $album->id }}">
                            <a href="{{ route('external.album', ['provider' => $provider, 'id' => $album->id]) }}" class="block aspect-square">
                                <x-artwork-thumb :src="$album->image" :seed="$album->title" icon="fa-solid fa-compact-disc" size="w-full h-full" rounded="rounded-none" class="text-3xl" />
                            </a>
                            <div class="px-2.5 py-2">
                                <a href="{{ route('external.album', ['provider' => $provider, 'id' => $album->id]) }}" class="block truncate text-[13px] font-medium leading-tight text-gray-900 hover:underline dark:text-gray-100" title="{{ $album->title }}">{{ $album->title }}</a>
                                @if($album->year)<p class="mt-0.5 text-[11px] text-gray-400 dark:text-gray-600">{{ $album->year }}</p>@endif
                                <div class="mt-1.5 flex items-center gap-3">
                                    <button type="button" wire:click="playExternal('album', '{{ $album->id }}')" aria-label="Play {{ $album->title }}" title="Play" class="text-[11px] text-gray-900 dark:text-gray-100"><i class="fa-solid fa-play"></i></button>
                                    @if($album->libraryId)
                                        <a href="{{ route('albums.show', $album->libraryId) }}" class="text-[11px] font-medium text-gray-900 underline dark:text-gray-100">In library</a>
                                    @endif
                                </div>
                            </div>
                        </x-card>
                    @endforeach
                </x-album-grid>
                @if(count($albums) > $limit)
                    <button type="button" wire:click="showAll" class="mt-5 text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-300">Show all {{ count($albums) }} albums</button>
                @endif
            @endif
        </section>
    @endif
</div>
