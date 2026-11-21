<div class="space-y-9" data-test="discover-hub">
    @if($notice)<x-alert type="warning">{{ $notice }}</x-alert>@endif

    @forelse($sections as $section)
        @php
            $shown = 'opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100';
            $id = 'discover-'.$section['key'];
            $web = fn (?array $art, string $size = 'proxy_320') => \App\Domain\Artwork\LibraryItemArtwork::webUrl($art[$size] ?? null);
        @endphp

        @if($section['kind'] === 'album')
            <x-shelf :title="$section['title']" :subtitle="$section['description']" :id="$id" data-test="{{ $section['key'] }}">
                @foreach($section['items'] as $item)
                    <x-media-card :href="route('albums.show', $item['id'])" :src="$web($item['artwork'])" :colors="$item['artwork']['colors'] ?? null"
                                  :title="$item['name']" :subtitle="$item['artist_name']" :note="$item['reason']" :seed="$item['name'].$item['id']"
                                  icon="fa-solid fa-compact-disc" :play="\App\Support\DeviceAction::template('albums.play', ['album' => $item['id']])" />
                @endforeach
            </x-shelf>
            @if($section['key'] === \App\Domain\Library\Discover::RANDOM_ALBUMS)
                <div class="-mt-6"><x-secondary-button size="sm" wire:click="anotherPick" data-test="another-pick"><i class="fa-solid fa-shuffle text-xs text-gray-400 dark:text-gray-500"></i>Another pick</x-secondary-button></div>
            @endif

        @elseif($section['kind'] === 'artist')
            <x-shelf :title="$section['title']" :subtitle="$section['description']" :id="$id" data-test="{{ $section['key'] }}">
                @foreach($section['items'] as $item)
                    <x-media-card :href="route('artists.show', $item['id'])" :src="$web($item['artwork'])" :colors="$item['artwork']['colors'] ?? null"
                                  :title="$item['name']" :subtitle="$item['album_count'].' '.Str::plural('album', $item['album_count'])" :note="$item['reason']" :seed="$item['name']" round
                                  icon="fa-solid fa-microphone-lines" :play="\App\Support\DeviceAction::template('artists.play', ['artist' => $item['id']])" />
                @endforeach
            </x-shelf>

        @elseif($section['kind'] === 'catalogue')
            <x-shelf :title="$section['title']" :subtitle="$section['description']" :id="$id" data-test="{{ $section['key'] }}">
                @foreach($section['items'] as $item)
                    <x-media-card :href="route('artists.show', $item['artist']['id'])" :src="$web($item['artwork'])" :colors="$item['artwork']['colors'] ?? null"
                                  :title="$item['title']" :subtitle="$item['artist']['name']" :note="$item['reason']" icon="fa-solid fa-compact-disc" />
                @endforeach
            </x-shelf>

        @elseif($section['kind'] === 'mode')
            {{-- Listening modes: a random selection of analysed tracks that fit, on the player pinned in this browser --}}
            <section id="{{ $id }}" data-test="{{ $section['key'] }}">
                <h2 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $section['title'] }}</h2>
                <p class="mb-3 mt-0.5 text-sm text-gray-500">{{ $section['description'] }}</p>
                <div class="flex flex-wrap gap-2.5">
                    @foreach($section['items'] as $mode)
                        <form method="POST" action="{{ route('library.listening-modes.play', $mode['key']) }}">
                            @csrf
                            <button type="submit" title="{{ $mode['description'] }}"
                                    class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white dark:bg-stone-900 border border-gray-200/70 dark:border-stone-800 text-sm text-gray-700 dark:text-gray-300 hover:border-gray-300 dark:hover:border-stone-600 transition-colors">
                                <i class="fa-solid fa-{{ $mode['icon'] }} text-xs text-gray-400 dark:text-gray-500"></i>{{ $mode['name'] }}
                                <span class="text-xs text-gray-400 dark:text-gray-600">{{ $mode['track_count'] }}</span>
                            </button>
                        </form>
                    @endforeach
                </div>
            </section>

        @elseif($section['kind'] === 'track')
            {{-- Songs as compact rows, like the recently played tracks of /library --}}
            <section id="{{ $id }}" data-test="{{ $section['key'] }}">
                <h2 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $section['title'] }}</h2>
                <p class="mb-3 mt-0.5 text-sm text-gray-500">{{ $section['description'] }}</p>
                <div class="grid gap-x-4 gap-y-1 sm:grid-cols-2 xl:grid-cols-3" data-test="track-rows">
                    @foreach($section['items'] as $item)
                        <div class="group flex items-center gap-3 rounded-2xl px-2 py-2 hover:bg-gray-100 dark:hover:bg-stone-900" data-test="track-{{ $item['id'] }}">
                            <x-artwork-thumb :src="$web($item['artwork'], 'proxy_120')" :colors="$item['artwork']['colors'] ?? null" :seed="$item['name']" icon="fa-solid fa-music" size="h-11 w-11" proxy="120" class="shrink-0" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" title="{{ $item['name'] }}">{{ $item['name'] }}</p>
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $item['artist_name'] }}</p>
                                <p class="truncate text-[11px] text-gray-400 dark:text-gray-600" title="{{ $item['reason'] }}">{{ $item['reason'] }}</p>
                            </div>
                            <button type="button" wire:click="playTrack({{ $item['id'] }})" aria-label="Play {{ $item['name'] }}" title="Play on the pinned player"
                                    class="flex h-9 w-9 items-center justify-center rounded-full text-gray-900 transition hover:bg-gray-200 dark:text-gray-100 dark:hover:bg-stone-800 {{ $shown }}"><i class="fa-solid fa-play text-sm"></i></button>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @empty
        <x-empty-state icon="compass" title="Nothing to rediscover yet" text="Play some music and come back: this page fills from your play history and favorites." />
    @endforelse
</div>
