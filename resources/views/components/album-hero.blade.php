@props(['page', 'devices', 'spotifyConnected' => false])
{{-- The top of /albums/{id} (DESIGN.md §10): the cover on the left (centred above the text on phones), the title once, the artist, one meta line, Play and Shuffle
     for the selected source, round buttons (add to playlist, "…"), all on the cover's tint fading into the page. All the album's metadata (genres, tags, release facts, listeners, a summary) sits in the hero.
     Queue ("play next / add to queue") belongs next to Add to playlist and renders once the pinned player reports a `queue_add` capability, which no driver has yet. --}}
@php
    $album = $page->album;
    $pinned = app(\App\Support\SelectedDevice::class)->device();
    // Play follows the selected source and the pinned player: it plays where that player can play the source,
    // is hidden where it can't (a Spotify device can only do Spotify), and opens the player panel while none is pinned.
    $source = $page->source;
    $sourceName = $source ? \App\Domain\Library\LibrarySources::sourceName($source) : null;
    $direct = $pinned !== null && $source !== null && \App\Domain\Library\LibrarySources::playableOn($source, $pinned);
    $hasSomething = $page->tracks->isNotEmpty() || $page->spotifyAlbum;
    $playable = $hasSomething && ($pinned === null ? $devices->isNotEmpty() : $direct);
    $action = fn (bool $shuffle) => \App\Support\DeviceAction::template('albums.play', ['album' => $album, 'source' => $page->source, 'shuffle' => $shuffle ? 1 : null]);
    $primary = 'inline-flex items-center justify-center gap-2 rounded-full bg-gray-900 px-6 py-3 text-base font-medium text-white shadow-sm transition hover:bg-gray-700 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200';
    $secondary = 'inline-flex items-center justify-center gap-2 rounded-full border border-black/20 bg-black/5 dark:border-white/25 px-6 py-3 text-base font-medium transition hover:bg-black/10 dark:bg-white/5 dark:hover:bg-white/10';
    $round = 'flex h-11 w-11 items-center justify-center rounded-full bg-black/10 transition hover:bg-black/20 dark:bg-white/10 dark:hover:bg-white/20';
    $label = $page->source ? \App\Domain\Library\LibrarySources::sourceName($page->source) : null;
@endphp
<div class="tint-hero relative overflow-hidden rounded-3xl" style="{{ \App\Domain\Artwork\ArtworkTint::style($album->colors) }}">
    <div class="flex items-center justify-between px-5 pt-5 sm:px-8 sm:pt-6">
        <x-back-button :href="route('artists.show', $album->artist)" title="{{ $album->artist->name }}" aria-label="Back to {{ $album->artist->name }}" />
        <form method="POST" action="{{ route('albums.favorite', $album) }}">
            @csrf
            <button type="submit" title="{{ $album->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}" aria-label="{{ $album->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}"
                    class="flex h-9 w-9 items-center justify-center rounded-xl text-xl transition-colors {{ $album->favorited_at ? 'text-rose-500 hover:text-rose-600' : 'opacity-60 hover:text-rose-400 hover:opacity-100' }}">
                <i class="{{ $album->favorited_at ? 'fa-solid' : 'fa-regular' }} fa-heart"></i>
            </button>
        </form>
    </div>

    <div class="flex flex-col items-center gap-6 px-5 pb-6 pt-3 text-center sm:px-8 md:flex-row md:items-end md:gap-8 md:text-left">
        <x-artwork-thumb :src="$album->images[0]['url'] ?? null" :colors="$album->colors" :seed="$album->name.$album->id" icon="fa-solid fa-compact-disc" :proxy="320"
                         size="h-56 w-56 sm:h-64 sm:w-64 md:h-72 md:w-72" rounded="rounded-2xl" class="text-5xl shadow-2xl ring-1 ring-black/10" />

        <div class="min-w-0 flex-1">
            <h1 class="text-3xl font-semibold leading-tight tracking-tight sm:text-4xl lg:text-5xl">{{ $album->name }}</h1>
            <a href="{{ route('artists.show', $album->artist) }}" class="mt-2 inline-flex items-center gap-1.5 text-lg opacity-80 hover:underline hover:opacity-100">{{ $album->artist->name }}<i class="fa-solid fa-chevron-right text-xs"></i></a>
            @if($page->metaLine !== '')
                <p class="mt-1 text-sm opacity-70">{{ $page->metaLine }}</p>
            @endif
            @php
                $facts = collect([
                    $page->details['release_date'] ? 'Released '.$page->details['release_date'] : null,
                    $page->details['catalog_number'] ? 'Cat. '.$page->details['catalog_number'] : null,
                    $page->details['country'],
                    $page->details['disc_count'] > 1 ? $page->details['disc_count'].' discs' : null,
                    $page->details['listeners'] ? number_format($page->details['listeners']).' listeners on Last.fm' : null,
                    $page->totalPlays > 0 ? number_format($page->totalPlays).' '.Str::plural('play', $page->totalPlays) : null,
                ])->filter()->implode(' · ');
            @endphp
            @if($facts !== '')
                <p class="mt-0.5 text-xs opacity-60">{{ $facts }}</p>
            @endif
            @if($page->genres || $page->tags)
                <div class="mt-3 flex flex-wrap justify-center gap-1.5 md:justify-start">
                    @foreach(array_slice($page->genres, 0, 5) as $genre)<x-badge class="!bg-black/10 dark:!bg-white/10 !text-inherit">{{ $genre }}</x-badge>@endforeach
                    @foreach($page->tags as $tag)<x-badge variant="outline" class="!text-inherit">{{ $tag }}</x-badge>@endforeach
                </div>
            @endif
            @if($page->details['summary'])
                <p class="mt-3 line-clamp-3 max-w-2xl text-sm leading-relaxed opacity-75">{{ $page->details['summary'] }}
                    @if($page->details['wikipedia_url'])<a href="{{ $page->details['wikipedia_url'] }}" target="_blank" rel="noopener" class="underline">Wikipedia</a>@endif
                </p>
            @endif

            <div class="mt-5 flex flex-wrap items-center justify-center gap-3 md:justify-start">
                @if($playable)
                    @foreach([['Play', 'play', false, $primary], ['Shuffle', 'shuffle', true, $secondary]] as [$text, $icon, $shuffle, $class])
                        @if($direct)
                            <form method="POST" action="{{ str_replace('{id}', $pinned->id, $action($shuffle)) }}">
                                @csrf
                                <button type="submit" class="{{ $class }}" title="{{ $text }} on {{ $pinned->device_name }}"><i class="fa-solid fa-{{ $icon }} text-sm"></i>{{ $text }}</button>
                            </form>
                        @else
                            <button type="button" class="{{ $class }}" title="Choose a player to {{ strtolower($text) }} on" @click="$dispatch('open-player-panel')"><i class="fa-solid fa-{{ $icon }} text-sm"></i>{{ $text }}</button>
                        @endif
                    @endforeach
                @endif

                {{-- Queue edits: on the pinned player, when it can (queue_add / queue_next) --}}
                @if($playable && $direct)
                    @php $queueCapabilities = \App\Domain\Device\DeviceCapabilities::for($pinned); @endphp
                    @foreach([['queue_next', 'Play next', true, 'forward-step'], ['queue_add', 'Add to queue', false, 'list-ol']] as [$capability, $text, $next, $icon])
                        @if(in_array($capability, $queueCapabilities, true))
                            <form method="POST" action="{{ route('devices.queue.add', $pinned) }}">
                                @csrf
                                <input type="hidden" name="type" value="album"><input type="hidden" name="id" value="{{ $album->id }}">
                                <input type="hidden" name="label" value="{{ $album->name }}"><input type="hidden" name="next" value="{{ $next ? 1 : 0 }}">
                                <button type="submit" class="{{ $secondary }}" title="{{ $text }} on {{ $pinned->device_name }}" data-test="album-{{ $capability }}"><i class="fa-solid fa-{{ $icon }} text-sm"></i>{{ $text }}</button>
                            </form>
                        @endif
                    @endforeach
                @endif

                <button type="button" class="{{ $secondary }}" title="Add to playlist" data-test="album-add-to-playlist"
                        @click="$dispatch('add-to-playlist', {{ \Illuminate\Support\Js::from(['album_id' => $album->id, 'source' => $page->source, 'label' => $album->name]) }})"><i class="fa-solid fa-list-ul text-sm"></i>Add to playlist</button>

                @php $canRemove = auth()->check() && $page->removable; @endphp
                @if($canRemove)
                    <x-menu label="More" :button="$round">
                        @if($canRemove)
                            <form method="POST" action="{{ route('albums.destroy', $album) }}" onsubmit="return confirm('Remove this album and its tracks from the library? Your play history is kept.')">
                                @csrf
                                @method('DELETE')
                                <x-menu.item type="submit" icon="trash-can" danger>Remove from library</x-menu.item>
                            </form>
                        @endif
                    </x-menu>
                @endif
            </div>

            @if($playable)
                <p class="mt-3 text-sm opacity-70">
                    @if($direct)
                        Plays on {{ $pinned->device_name }}
                    @else
                        <button type="button" class="underline hover:opacity-100" @click="$dispatch('open-player-panel')">Choose a player</button>
                    @endif
                    @if($sourceName && count($page->sources) > 1) · from {{ $sourceName }} @endif
                </p>
            @elseif($hasSomething && $pinned && $sourceName)
                <p class="mt-3 text-sm opacity-70">{{ $pinned->device_name }} can't play {{ $sourceName }}. <button type="button" class="underline hover:opacity-100" @click="$dispatch('open-player-panel')">Choose another player</button></p>
            @endif
        </div>
    </div>
</div>
