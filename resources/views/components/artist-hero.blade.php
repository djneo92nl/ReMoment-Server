@props(['artist', 'devices', 'totalPlays' => 0, 'totalSeconds' => 0, 'albumCount' => 0, 'trackCount' => 0, 'source' => null])
{{-- The top of /artists/{id} (DESIGN.md §10), the sibling of the album hero: the round photo left of the text (centred above it on phones), the name once,
     one meta line, the artist's facts, genre and tag chips, a clamped bio and link row, Play and Shuffle on the pinned player, a "…" for the admin removal,
     all on the tint of the most played cover fading into the page. Fanart, when there is some, sits dimmed behind. --}}
@php
    $pinned = app(\App\Support\SelectedDevice::class)->device();
    $coverAlbum = $artist->coverAlbum();
    $colors = $coverAlbum?->colors ?? [];
    $genres = $artist->genres();
    $country = $artist->country();
    $details = $artist->details();
    $links = (array) $details['links'];
    $photo = $artist->photoUrl() ?? \App\Domain\Artwork\LibraryArtwork::coverUrl($coverAlbum?->images);
    // TheAudioDB's photos carry no date and often show the artist years ago, so a different one each visit rather than always the first.
    $gallery = collect($artist->galleryUrls())->shuffle()->values();
    $fanart = \App\Domain\Artwork\LibraryItemArtwork::webUrl(\App\Domain\Artwork\LibraryItemArtwork::fanart($gallery->first())['proxy_bg'] ?? null);
    $extraTags = collect([$details['mood'], $details['style'], ...$details['tags']])->filter()
        ->reject(fn ($tag) => collect($genres)->contains(fn ($g) => strcasecmp($g, $tag) === 0))->unique()->take(6);
    $lifeSpan = $details['begin_date'] ? ($details['end_date'] ? substr($details['begin_date'], 0, 4).' – '.substr($details['end_date'], 0, 4) : 'Since '.substr($details['begin_date'], 0, 4)) : null;

    $meta = collect([
        $albumCount > 0 ? $albumCount.' '.Str::plural('album', $albumCount) : null,
        $trackCount > 0 ? $trackCount.' '.Str::plural('track', $trackCount) : null,
        $totalPlays > 0 ? number_format($totalPlays).' '.Str::plural('play', $totalPlays) : null,
        $totalSeconds > 0 ? \App\Domain\Helpers\TimeHelper::humanDuration((int) $totalSeconds).' listened' : null,
    ])->filter()->implode(' · ');
    $facts = collect([
        ucfirst((string) $details['type']) ?: null,
        $lifeSpan,
        $country ? ($details['area'] ? $details['area'].', ' : '').$country : null,
        $details['listeners'] ? number_format($details['listeners']).' listeners · '.number_format($details['playcount']).' scrobbles on Last.fm' : null,
    ])->filter()->implode(' · ');

    // Play follows the selected source and the pinned player, as on the album page: it plays where that player can play the source.
    $sourceName = $source ? \App\Domain\Library\LibrarySources::LIBRARIES[$source]['name'] : null;
    $direct = $pinned !== null && $source !== null && \App\Domain\Library\LibrarySources::playableOn($source, $pinned);
    $playable = $trackCount > 0 && ($pinned === null ? $devices->isNotEmpty() : $direct);
    $action = fn (bool $shuffle) => \App\Support\DeviceAction::template('artists.play', ['artist' => $artist, 'source' => $source, 'shuffle' => $shuffle ? 1 : null]);
    $primary = 'inline-flex items-center justify-center gap-2 rounded-full bg-gray-900 px-6 py-3 text-base font-medium text-white shadow-sm transition hover:bg-gray-700 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200';
    $secondary = 'inline-flex items-center justify-center gap-2 rounded-full border border-black/20 bg-black/5 dark:border-white/25 px-6 py-3 text-base font-medium transition hover:bg-black/10 dark:bg-white/5 dark:hover:bg-white/10';
    $round = 'flex h-11 w-11 items-center justify-center rounded-full bg-black/10 transition hover:bg-black/20 dark:bg-white/10 dark:hover:bg-white/20';
    $admin = auth()->check();
@endphp
<div class="tint-hero relative overflow-hidden rounded-3xl" style="{{ \App\Domain\Artwork\ArtworkTint::style($colors) }}">
    @if($fanart)
        <div class="pointer-events-none absolute inset-0 opacity-30 xl:opacity-40 [mask-image:linear-gradient(to_left,#000,transparent_55%)]" style="background: url('{{ $fanart }}') center/cover"></div>
    @endif

    @php $collage = $gallery->map(fn ($u) => \App\Domain\Artwork\LibraryItemArtwork::proxy($u, 320))->filter()->take(3)->values(); @endphp
    @if($collage->count() > 1)
        {{-- A tilted collage of the artist's gallery, fading out to the left like the library hub's --}}
        <div class="hero-collage pointer-events-none absolute inset-y-0 right-0 hidden w-1/3 items-center justify-center gap-3 xl:flex" aria-hidden="true">
            @foreach($collage as $i => $thumb)
                <img src="{{ $thumb }}" alt="" loading="lazy" class="h-64 w-32 rounded-2xl object-cover shadow-xl ring-1 ring-black/10 {{ ['-rotate-6 translate-y-4', 'rotate-3 -translate-y-6', '-rotate-3 translate-y-8'][$i] }}">
            @endforeach
        </div>
    @endif

    <div class="relative flex items-center justify-between px-5 pt-5 sm:px-8 sm:pt-6">
        <x-back-button :href="route('artists.index')" title="Artists" aria-label="Back to artists" />
        <form method="POST" action="{{ route('artists.favorite', $artist) }}">
            @csrf
            <button type="submit" title="{{ $artist->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}" aria-label="{{ $artist->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}"
                    class="flex h-9 w-9 items-center justify-center rounded-xl text-xl transition-colors {{ $artist->favorited_at ? 'text-rose-500 hover:text-rose-600' : 'opacity-60 hover:text-rose-400 hover:opacity-100' }}">
                <i class="{{ $artist->favorited_at ? 'fa-solid' : 'fa-regular' }} fa-heart"></i>
            </button>
        </form>
    </div>

    <div class="relative flex flex-col items-center gap-6 px-5 pb-6 pt-3 text-center sm:px-8 md:flex-row md:items-center md:gap-10 md:text-left">
        <x-artwork-thumb :src="$photo" :colors="$colors" :seed="$artist->name" icon="fa-solid fa-microphone-lines" :proxy="320"
                         size="h-44 w-44 sm:h-52 sm:w-52 md:h-64 md:w-64 lg:h-72 lg:w-72" rounded="rounded-full" class="text-5xl shadow-2xl ring-1 ring-black/10" />

        <div class="min-w-0 flex-1">
            <h1 class="text-3xl font-semibold leading-tight tracking-tight sm:text-4xl lg:text-5xl">{{ $artist->name }}</h1>
            @if($meta !== '')
                <p class="mt-2 text-sm opacity-70">{{ $meta }}</p>
            @endif
            @if($facts !== '')
                <p class="mt-0.5 text-xs opacity-60">{{ $facts }}</p>
            @endif
            @if($genres || $extraTags->isNotEmpty())
                <div class="mt-3 flex flex-wrap justify-center gap-1.5 md:justify-start">
                    @foreach(array_slice($genres, 0, 5) as $genre)<x-badge class="!bg-black/10 dark:!bg-white/10 !text-inherit">{{ $genre }}</x-badge>@endforeach
                    @foreach($extraTags as $tag)<x-badge variant="outline" class="!text-inherit">{{ $tag }}</x-badge>@endforeach
                </div>
            @endif
            @if($details['bio'])
                <p class="mt-3 line-clamp-3 max-w-2xl text-sm leading-relaxed opacity-75">{{ $details['bio'] }}</p>
            @endif
            @if($details['wikipedia_url'] || $links)
                <div class="mt-2 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-xs opacity-75 md:justify-start">
                    @if($details['wikipedia_url'])
                        <a href="{{ $details['wikipedia_url'] }}" target="_blank" rel="noopener" class="underline hover:opacity-100">Wikipedia</a>
                    @endif
                    @foreach(['official' => 'Website', 'discogs' => 'Discogs', 'bandcamp' => 'Bandcamp', 'allmusic' => 'AllMusic', 'lastfm' => 'Last.fm', 'instagram' => 'Instagram'] as $linkKey => $linkLabel)
                        @isset($links[$linkKey])
                            <a href="{{ $links[$linkKey] }}" target="_blank" rel="noopener" class="underline hover:opacity-100">{{ $linkLabel }}</a>
                        @endisset
                    @endforeach
                </div>
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

                @if($admin)
                    <x-menu label="More" :button="$round">
                        <a href="{{ route('artists.settings', $artist) }}" role="menuitem" class="flex w-full items-center gap-2.5 px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-stone-700"><i class="fa-solid fa-sliders w-4 text-xs text-gray-400"></i>Artist settings</a>
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
                    @if($sourceName) · from {{ $sourceName }} @endif
                </p>
            @elseif($trackCount > 0 && $pinned && $sourceName)
                <p class="mt-3 text-sm opacity-70">{{ $pinned->device_name }} can't play {{ $sourceName }}. <button type="button" class="underline hover:opacity-100" @click="$dispatch('open-player-panel')">Choose another player</button></p>
            @endif
        </div>
    </div>
</div>
