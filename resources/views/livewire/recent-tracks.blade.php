<div data-test="recent-tracks">
    @if($rows !== [])
        @php($shown = 'opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100')
        <section>
            <div class="mb-3 flex items-center justify-between gap-4">
                <h2 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100"><a href="{{ route('history.index') }}" class="inline-flex items-center gap-1.5 hover:underline">{{ $title }}<i class="fa-solid fa-chevron-right text-xs text-gray-400 dark:text-gray-600"></i></a></h2>
            </div>
            @if($notice)<p class="mb-2 text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</p>@endif
            <div class="grid gap-x-4 gap-y-1 sm:grid-cols-2 xl:grid-cols-3" data-test="track-rows">
                @foreach(array_slice($rows, 0, $limit) as $row)
                    <div class="group flex items-center gap-3 rounded-2xl px-2 py-2 hover:bg-gray-100 dark:hover:bg-stone-900" data-test="{{ $row['kind'] }}-{{ $row['id'] }}">
                        <x-artwork-thumb :src="$row['image']" :colors="$row['colors']" :seed="$row['title']" icon="fa-solid fa-music" size="h-11 w-11" proxy="120" class="shrink-0" />
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-1.5 truncate text-sm font-medium text-gray-900 dark:text-gray-100" title="{{ $row['title'] }}"><span class="truncate">{{ $row['title'] }}</span>
                                @if($row['source'])<x-source-icon :source="$row['source']" />@endif
                                @if(($row['libraryId'] ?? null))<i class="fa-solid fa-circle-check text-[10px] text-gray-400 dark:text-gray-600" title="In your library"></i>@endif</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $row['artist'] }}</p>
                        </div>
                        @if($row['kind'] === 'library')
                            <button type="button" wire:click="favorite({{ $row['id'] }})" data-test="fav-{{ $row['id'] }}" aria-label="{{ $row['favorite'] ? 'Remove from favorites' : 'Favorite' }}"
                                    class="flex h-9 w-9 items-center justify-center transition {{ $row['favorite'] ? 'text-rose-500' : $shown.' text-gray-400 hover:text-rose-500 dark:text-gray-500' }}"><i class="fa-{{ $row['favorite'] ? 'solid' : 'regular' }} fa-heart text-sm"></i></button>
                            <button type="button" wire:click="playLibrary({{ $row['id'] }})" aria-label="Play {{ $row['title'] }}" title="Play on the pinned player"
                                    class="flex h-9 w-9 items-center justify-center rounded-full text-gray-900 transition hover:bg-gray-200 dark:text-gray-100 dark:hover:bg-stone-800 {{ $shown }}"><i class="fa-solid fa-play text-sm"></i></button>
                        @else
                            {{-- A streaming track: want it (wishlist), never favorite it --}}
                            <button type="button" wire:click="toggleWant(@js(['title' => $row['title'], 'artist' => $row['artist'], 'album' => null, 'image' => $row['image']]))" data-test="want-{{ $row['id'] }}"
                                    aria-label="{{ $wished[$row['id']] ? 'Take off the wishlist' : 'Add to the wishlist' }}" title="{{ $wished[$row['id']] ? 'On the wishlist' : 'Want to buy or rip this song' }}"
                                    class="flex h-9 w-9 items-center justify-center transition {{ $wished[$row['id']] ? 'text-amber-500' : $shown.' text-gray-400 hover:text-amber-500 dark:text-gray-500' }}"><i class="fa-{{ $wished[$row['id']] ? 'solid' : 'regular' }} fa-star text-sm"></i></button>
                            <button type="button" wire:click="playExternal('track', '{{ $row['id'] }}')" aria-label="Play {{ $row['title'] }}" title="Play on the pinned player"
                                    class="flex h-9 w-9 items-center justify-center rounded-full text-gray-900 transition hover:bg-gray-200 dark:text-gray-100 dark:hover:bg-stone-800 {{ $shown }}"><i class="fa-solid fa-play text-sm"></i></button>
                        @endif
                    </div>
                @endforeach
            </div>
            @if(count($rows) > $limit)
                <button type="button" wire:click="showAll" class="mt-3 text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-300">Show all {{ count($rows) }}</button>
            @endif
        </section>
    @endif
</div>
