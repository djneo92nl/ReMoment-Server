<div wire:init="load" data-test="section-{{ $section }}">
    @if($loaded && ($error || $items === []))
        {{-- Nothing to shelve: a quiet line, not an empty block --}}
        <p class="text-sm text-gray-400 dark:text-gray-600"><i class="fa-brands fa-spotify mr-1.5 text-emerald-500"></i>{{ $title }}: {{ $error ?? 'nothing yet' }}</p>
    @elseif($section !== 'saved-albums')
        {{-- Tracks as rows, like the radio stations: artwork, two lines, play and star on hover (always on touch) --}}
        @php($shown = 'opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100')
        <section>
            <h2 class="mb-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $title }} on Spotify</h2>
            @if(!$loaded)<p class="py-3 text-sm text-gray-400 dark:text-gray-600"><i class="fa-brands fa-spotify mr-1.5 text-emerald-500"></i>Loading from Spotify…</p>@endif
            @if($notice)<p class="mb-2 text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</p>@endif
            <div class="grid gap-x-4 gap-y-1 sm:grid-cols-2 xl:grid-cols-3" data-test="track-rows">
                @foreach(array_slice($items, 0, $limit) as $track)
                    <div class="group flex items-center gap-3 rounded-2xl px-2 py-2 hover:bg-gray-100 dark:hover:bg-stone-900" data-test="track-{{ $track->id }}">
                        <x-artwork-thumb :src="$track->image" :seed="$track->title" icon="fa-solid fa-music" size="h-11 w-11" class="shrink-0" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" title="{{ $track->title }}">{{ $track->title }}@if($track->libraryId) <i class="fa-solid fa-circle-check ml-0.5 text-[10px] text-gray-400 dark:text-gray-600" title="In your library"></i>@endif</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $track->artist }}</p>
                        </div>
                        <button type="button" wire:click="toggleWant(@js($payload($track)))" data-test="want-{{ $track->id }}"
                                aria-label="{{ $wished[$track->id] ? 'Take off the wishlist' : 'Add to the wishlist' }}" title="{{ $wished[$track->id] ? 'On the wishlist' : 'Want to buy or rip this song' }}"
                                class="flex h-9 w-9 items-center justify-center transition {{ $wished[$track->id] ? 'text-amber-500' : $shown.' text-gray-400 hover:text-amber-500 dark:text-gray-500' }}"><i class="fa-{{ $wished[$track->id] ? 'solid' : 'regular' }} fa-star text-sm"></i></button>
                        <button type="button" wire:click="playExternal('track', '{{ $track->id }}')" aria-label="Play {{ $track->title }}" title="Play on the pinned player"
                                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-900 transition hover:bg-gray-200 dark:text-gray-100 dark:hover:bg-stone-800 {{ $shown }}"><i class="fa-solid fa-play text-sm"></i></button>
                    </div>
                @endforeach
            </div>
            @if(count($items) > $limit)
                <button type="button" wire:click="showAll" class="mt-3 text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-300">Show all {{ count($items) }}</button>
            @endif
        </section>
    @else
        <x-shelf :title="$title.' on Spotify'">
            @if(!$loaded)
                <p class="py-6 text-sm text-gray-400 dark:text-gray-600"><i class="fa-brands fa-spotify mr-1.5 text-emerald-500"></i>Loading from Spotify…</p>
            @endif
            @if($notice)<p class="w-full py-2 text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</p>@endif
            @foreach(array_slice($items, 0, 20) as $item)
                @php($isAlbum = $item->type === 'album')
                <x-card compact flush class="group w-36 shrink-0 snap-start sm:w-40 lg:w-44" data-test="{{ $item->type }}-{{ $item->id }}">
                    <div class="relative aspect-square">
                        @if($isAlbum)
                            <a href="{{ route('external.album', ['provider' => $provider, 'id' => $item->id]) }}" class="block h-full">
                                <x-artwork-thumb :src="$item->image" :seed="$item->title" icon="fa-solid fa-compact-disc" size="w-full h-full" rounded="rounded-none" class="text-3xl" />
                            </a>
                        @else
                            <x-artwork-thumb :src="$item->image" :seed="$item->title" icon="fa-solid fa-music" size="w-full h-full" rounded="rounded-none" class="text-3xl" />
                        @endif
                        <button type="button" wire:click="playExternal('{{ $item->type }}', '{{ $item->id }}')" aria-label="Play {{ $item->title }}" title="Play on the pinned player"
                                class="absolute bottom-2 right-2 flex h-9 w-9 items-center justify-center rounded-full bg-white text-gray-900 shadow-md transition hover:scale-105 [@media(hover:hover)]:opacity-0 group-hover:opacity-100 group-focus-within:opacity-100"><i class="fa-solid fa-play text-xs"></i></button>
                    </div>
                    <div class="flex items-start gap-1 px-3 py-2.5">
                        <div class="min-w-0 flex-1">
                            @if($isAlbum)
                                <a href="{{ route('external.album', ['provider' => $provider, 'id' => $item->id]) }}" class="block truncate text-[13px] font-medium leading-tight text-gray-900 hover:underline dark:text-gray-100" title="{{ $item->title }}">{{ $item->title }}</a>
                            @else
                                <p class="truncate text-[13px] font-medium leading-tight text-gray-900 dark:text-gray-100" title="{{ $item->title }}">{{ $item->title }}@if($item->libraryId) <i class="fa-solid fa-circle-check ml-0.5 text-[10px] text-gray-400 dark:text-gray-600" title="In your library"></i>@endif</p>
                            @endif
                            <p class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-600">{{ $item->artist }}</p>
                        </div>
                        @unless($isAlbum)
                            <button type="button" wire:click="toggleWant(@js($payload($item)))" data-test="want-{{ $item->id }}"
                                    aria-label="{{ $wished[$item->id] ? 'Take off the wishlist' : 'Add to the wishlist' }}" title="{{ $wished[$item->id] ? 'On the wishlist' : 'Want to buy or rip this song' }}"
                                    class="flex-none text-xs {{ $wished[$item->id] ? 'text-amber-500' : 'text-gray-300 dark:text-stone-600' }}"><i class="fa-{{ $wished[$item->id] ? 'solid' : 'regular' }} fa-star"></i></button>
                        @endunless
                    </div>
                </x-card>
            @endforeach
        </x-shelf>
    @endif
</div>
