<div wire:init="load">
    @if($available)
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">
                    <i class="fa-brands fa-spotify text-emerald-500 mr-1.5"></i>More on Spotify
                </h2>
                @if(count($albums) > 1)
                    <button wire:click="addAll" wire:loading.attr="disabled" type="button"
                            class="px-3 py-1 rounded-full bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-xs text-gray-600 dark:text-gray-400 transition-colors">
                        Add all {{ count($albums) }}
                    </button>
                @endif
            </div>

            @if(!$loaded)
                <p class="text-sm text-gray-400 dark:text-gray-600"><i class="fa-solid fa-circle-notch fa-spin mr-1.5"></i>Looking on Spotify…</p>
            @elseif($failed)
                <p class="text-sm text-gray-400 dark:text-gray-600">Spotify could not be reached.</p>
            @elseif(count($albums) === 0)
                <p class="text-sm text-gray-400 dark:text-gray-600">Nothing more on Spotify: the library has all of this artist's albums.</p>
            @else
                <x-album-grid>
                    @foreach($shown as $album)
                        <div class="min-w-0 rounded-xl bg-white dark:bg-stone-900 border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">
                            <div style="aspect-ratio:1/1">
                                <x-artwork-thumb :src="$album['image']" :seed="$album['name']" icon="fa-solid fa-compact-disc"
                                                 size="w-full h-full" rounded="rounded-none" class="text-3xl" />
                            </div>
                            <div class="px-2.5 py-2">
                                <p class="text-[13px] font-medium leading-tight text-gray-900 dark:text-gray-100 truncate" title="{{ $album['name'] }}">{{ $album['name'] }}</p>
                                @if($album['year'])<p class="text-[11px] text-gray-400 dark:text-gray-600 mt-0.5">{{ $album['year'] }}</p>@endif
                                @if(in_array($album['id'], $queued, true))
                                    <p class="mt-1.5 text-[11px] text-emerald-600 dark:text-emerald-400"><i class="fa-solid fa-check mr-1"></i>Adding…</p>
                                @else
                                    <button wire:click="add('{{ $album['id'] }}')" type="button"
                                            class="mt-1.5 text-[11px] font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                                        <i class="fa-solid fa-plus mr-1"></i>Add to library
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </x-album-grid>
                @if(count($albums) > count($shown))
                    <button wire:click="showAll" type="button" class="mt-5 text-xs font-medium text-gray-500 hover:text-gray-800 dark:hover:text-gray-300">
                        Show all {{ count($albums) }} albums
                    </button>
                @endif
            @endif
        </div>
    @endif
</div>
