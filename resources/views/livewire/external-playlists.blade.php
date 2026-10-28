<div>
    @if(!$connected)
        <x-empty-state icon="fa-brands fa-spotify" :title="$name.' is not connected'" text="Connect it under Settings to see your streaming playlists here." />
    @elseif($failed)
        <x-alert type="warning">{{ $name }} could not be reached. Try again in a moment.</x-alert>
    @elseif($playlists === [])
        <x-empty-state icon="list-ul" text="{{ $name }} has no playlists for this account." />
    @else
        @if($notice)<p class="mb-3 text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</p>@endif
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" data-test="streaming-playlists">
            @foreach($playlists as $playlist)
                <x-card compact class="group p-5" data-test="playlist-{{ $playlist->id }}">
                    <div class="flex items-center gap-4">
                        <a href="{{ route('external.playlist', ['provider' => $provider, 'id' => $playlist->id]) }}" class="h-12 w-12 flex-none overflow-hidden rounded-xl">
                            <x-artwork-thumb :src="$playlist->image" :seed="$playlist->title" icon="fa-solid fa-list-ul" size="w-full h-full" rounded="rounded-none" />
                        </a>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('external.playlist', ['provider' => $provider, 'id' => $playlist->id]) }}" class="block truncate font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $playlist->title }}</a>
                            <p class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-600">{{ $playlist->trackCount !== null ? number_format($playlist->trackCount).' '.\Illuminate\Support\Str::plural('track', $playlist->trackCount) : '' }}{{ $playlist->artist ? ' · '.$playlist->artist : '' }}</p>
                        </div>
                        <button type="button" wire:click="playExternal('playlist', '{{ $playlist->id }}')" aria-label="Play {{ $playlist->title }}" title="Play on the pinned player"
                                class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-sm text-gray-900 hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-stone-800"><i class="fa-solid fa-play"></i></button>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</div>
