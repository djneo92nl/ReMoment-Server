{{-- The discography of an artist (MusicBrainz: albums and EPs) or the household wishlist, as the "More on Spotify" panel is built:
     a card with a section label, a grid of compact cards, "Show all". Owned = the library has files; "On Spotify" = streamable only; "Not in your library" = only known. --}}
<div @if($pending) wire:poll.6s @endif>
    @if($artist ? $hasRows : true)
        <x-card class="p-6 md:p-8">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">
                        <i class="fa-solid {{ $artist ? 'fa-record-vinyl' : 'fa-star' }} mr-1.5"></i>{{ $artist ? 'Discography' : 'Wishlist' }}
                    </h2>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">
                        {{ $artist ? 'Albums and EPs from MusicBrainz. Wish for what you want to buy or rip.' : 'Albums you want to buy or rip. A wish closes by itself once the album is in your library as files.' }}
                    </p>
                </div>
                @if($artist)
                    <div class="no-scrollbar -mx-1 flex min-w-0 gap-1 overflow-x-auto px-1" role="group" aria-label="Filter">
                        @foreach(['all' => 'All', 'missing' => 'Not in your library', 'wanted' => 'Wished'] as $key => $label)
                            <button type="button" wire:click="setFilter('{{ $key }}')" data-test="filter-{{ $key }}"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors {{ $filter === $key ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-stone-800 dark:text-gray-400 dark:hover:bg-stone-700' }}">
                                {{ $label }} <span class="text-xs opacity-60">{{ $counts[$key] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            @if($items === [] && $songs === [])
                <p class="text-sm text-gray-400 dark:text-gray-600">
                    {{ $artist ? 'No entry matches this filter.' : 'Nothing wished for yet. Open an artist and press Want on an album you miss.' }}
                </p>
            @else
                @if($songs !== [])
                    <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Songs</h3>
                    <ul class="mb-6 divide-y divide-gray-100 dark:divide-stone-800/70" data-test="wished-songs">
                        @foreach($songs as $song)
                            <li class="flex items-center gap-3 py-2.5">
                                <x-artwork-thumb :src="$song['artwork']['proxy_120'] ?? null" :seed="$song['title']" icon="fa-solid fa-music" size="w-10 h-10" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $song['title'] }}</p>
                                    <p class="truncate text-xs text-gray-400 dark:text-gray-600">{{ $song['artist'] }}{{ $song['album'] ? ' · '.$song['album'] : '' }}</p>
                                </div>
                                @if($song['track_id'])<x-badge variant="info">In your library</x-badge>@endif
                                <button wire:click="removeSong({{ $song['id'] }})" type="button" class="text-xs text-gray-400 underline hover:text-gray-600 dark:text-gray-600" title="Take off the wishlist">Remove</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if($items !== [])
                <x-album-grid>
                    @foreach($shown as $item)
                        @php
                            $libraries = array_values(array_unique(array_filter(array_column($item['availability'], 'library'))));
                            $devicesFor = collect($libraries)->flatMap(fn ($l) => $playsOn[$l] ?? [])->unique()->values();
                            $streamOnly = $item['state'] === 'streamable' && $item['album_id'] === null;
                        @endphp
                        <x-card compact flush class="min-w-0" data-test="catalogue-{{ $item['id'] }}">
                            <div class="aspect-square">
                                @if($item['album_id'])
                                    <a href="{{ route('albums.show', $item['album_id']) }}" class="block h-full">
                                @endif
                                <x-artwork-thumb :src="$item['artwork']['proxy_320'] ?? null" :seed="$item['title']" icon="fa-solid fa-compact-disc"
                                                 size="w-full h-full" rounded="rounded-none" class="text-3xl" />
                                @if($item['album_id'])
                                    </a>
                                @endif
                            </div>
                            <div class="space-y-1.5 px-2.5 py-2">
                                <div>
                                    <p class="truncate text-[13px] font-medium leading-tight text-gray-900 dark:text-gray-100" title="{{ $item['title'] }}">{{ $item['title'] }}</p>
                                    <p class="mt-0.5 truncate text-[11px] text-gray-400 dark:text-gray-600">
                                        {{ $artist ? '' : $item['artist']['name'].' · ' }}{{ $item['year'] ?? 'Year unknown' }}{{ $item['type'] === 'ep' ? ' · EP' : '' }}
                                    </p>
                                </div>

                                <div class="flex flex-wrap gap-1">
                                    @if($item['state'] === 'owned')
                                        <x-badge variant="success">Owned</x-badge>
                                    @elseif($item['album_id'])
                                        <x-badge variant="info">In your library</x-badge>
                                    @elseif($streamOnly)
                                        <x-badge variant="info">On Spotify</x-badge>
                                    @else
                                        <x-badge>Not in your library</x-badge>
                                    @endif
                                    @if($item['wanted'] && !$item['fulfilled'])<x-badge variant="warning">Wished</x-badge>@endif
                                    @if($item['ignored'])<x-badge variant="outline">Hidden</x-badge>@endif
                                </div>

                                @if($libraries !== [])
                                    <p class="text-[11px] leading-snug text-gray-400 dark:text-gray-600">
                                        {{ $devicesFor->isEmpty() ? 'No player can play this yet' : 'Plays on '.$devicesFor->take(3)->implode(', ').($devicesFor->count() > 3 ? ' +'.($devicesFor->count() - 3) : '') }}
                                    </p>
                                @endif

                                <div class="flex flex-wrap gap-x-3 gap-y-1 pt-0.5">
                                    @if(!$item['album_id'] || !$item['fulfilled'])
                                        @if($item['wanted'])
                                            <button wire:click="want({{ $item['id'] }}, false)" type="button" class="text-[11px] font-medium text-gray-900 underline dark:text-gray-100" title="Take off the wishlist"><i class="fa-solid fa-star mr-1"></i>Wished</button>
                                        @elseif(!$item['ignored'] && $item['state'] !== 'owned')
                                            <button wire:click="want({{ $item['id'] }})" type="button" class="text-[11px] font-medium text-gray-900 underline dark:text-gray-100" title="Put on the wishlist: an album you want to buy or rip"><i class="fa-regular fa-star mr-1"></i>Want</button>
                                        @endif
                                    @endif
                                    @if($streamOnly && $canImport)
                                        @if(in_array($item['id'], $queued, true))
                                            <span class="text-[11px] text-emerald-600 dark:text-emerald-400"><i class="fa-solid fa-check mr-1"></i>Adding…</span>
                                        @else
                                            <button wire:click="add({{ $item['id'] }})" type="button" class="text-[11px] font-medium text-gray-900 underline dark:text-gray-100"><i class="fa-solid fa-plus mr-1"></i>Add to library</button>
                                        @endif
                                    @endif
                                    @if($item['ignored'])
                                        <button wire:click="ignore({{ $item['id'] }}, false)" type="button" class="text-[11px] font-medium text-gray-500 underline">Show again</button>
                                    @elseif($artist && $item['state'] !== 'owned' && !$item['album_id'])
                                        <button wire:click="ignore({{ $item['id'] }})" type="button" class="text-[11px] text-gray-400 underline hover:text-gray-600 dark:text-gray-600" title="Hide this from the discography">Not interested</button>
                                    @endif
                                </div>
                            </div>
                        </x-card>
                    @endforeach
                </x-album-grid>
                @endif

                <div class="mt-5 flex flex-wrap items-center gap-4">
                    @if(count($items) > count($shown))
                        <button wire:click="showAll" type="button" class="text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-300">Show all {{ count($items) }} entries</button>
                    @endif
                    @if($artist)
                        <button wire:click="$toggle('showHidden')" type="button" class="text-xs font-medium text-gray-400 hover:text-gray-700 dark:hover:text-gray-300">{{ $showHidden ? 'Hide' : 'Show' }} the ones I'm not interested in</button>
                    @endif
                </div>
            @endif
        </x-card>
    @endif
</div>
