<div wire:init="load">
    @if($available)
        <x-card class="p-6 md:p-8">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">
                    <i class="fa-brands fa-spotify text-emerald-500 mr-1.5"></i>More on Spotify
                </h2>
                @if($spotifyArtistId)
                    <a href="{{ route('external.artist', ['provider' => 'spotify', 'id' => $spotifyArtistId]) }}" class="px-3 py-1 rounded-full bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-xs text-gray-600 dark:text-gray-400 transition-colors">
                        Browse all on Spotify
                    </a>
                @endif
                @if(count($albums) > 1)
                    <button wire:click="addAll" wire:loading.attr="disabled" type="button"
                            class="px-3 py-1 rounded-full bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-xs text-gray-600 dark:text-gray-400 transition-colors">
                        Add all {{ count($albums) }}
                    </button>
                @endif
            </div>

            @if($notice)<p class="mb-3 text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</p>@endif

            @if(!$loaded)
                <p class="text-sm text-gray-400 dark:text-gray-600"><i class="fa-solid fa-circle-notch fa-spin mr-1.5"></i>Looking on Spotify…</p>
            @elseif($failed)
                <p class="text-sm text-gray-400 dark:text-gray-600">Spotify could not be reached.</p>
            @elseif(count($albums) === 0)
                <p class="text-sm text-gray-400 dark:text-gray-600">Nothing more on Spotify: the library has all of this artist's albums.</p>
            @else
                <x-album-grid>
                    @foreach($shown as $album)
                        <x-card compact flush class="min-w-0">
                            <a href="{{ route('external.album', ['provider' => 'spotify', 'id' => $album['id']]) }}" class="block aspect-square" title="See the tracks on Spotify">
                                <x-artwork-thumb :src="$album['image']" :seed="$album['name']" icon="fa-solid fa-compact-disc"
                                                 size="w-full h-full" rounded="rounded-none" class="text-3xl" />
                            </a>
                            <div class="px-2.5 py-2">
                                <a href="{{ route('external.album', ['provider' => 'spotify', 'id' => $album['id']]) }}" class="block truncate text-[13px] font-medium leading-tight text-gray-900 hover:underline dark:text-gray-100" title="{{ $album['name'] }}">{{ $album['name'] }}</a>
                                @if($album['year'])<p class="text-[11px] text-gray-400 dark:text-gray-600 mt-0.5">{{ $album['year'] }}</p>@endif
                                <div class="mt-1.5 flex items-center gap-3">
                                    <button wire:click="play('{{ $album['id'] }}')" type="button" aria-label="Play {{ $album['name'] }}" title="Play from Spotify on the pinned player" class="text-[11px] text-gray-900 dark:text-gray-100"><i class="fa-solid fa-play"></i></button>
                                    @if(in_array($album['id'], $queued, true))
                                        <span class="text-[11px] text-emerald-600 dark:text-emerald-400"><i class="fa-solid fa-check mr-1"></i>Adding…</span>
                                    @else
                                        <button wire:click="add('{{ $album['id'] }}')" type="button"
                                                class="text-[11px] font-medium text-gray-900 dark:text-gray-100 underline">
                                            <i class="fa-solid fa-plus mr-1"></i>Add to library
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </x-card>
                    @endforeach
                </x-album-grid>
                @if(count($albums) > count($shown))
                    <button wire:click="showAll" type="button" class="mt-5 text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-300">
                        Show all {{ count($albums) }} albums
                    </button>
                @endif
            @endif
        </x-card>
    @endif
</div>
