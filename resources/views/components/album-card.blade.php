@props(['album', 'showPlays' => false, 'detail' => false, 'tinted' => false, 'quick' => null])

@php
    // `tinted`: a shelf card (fixed width, footer tinted by the cover, quick-play); otherwise a grid cell.
    $quick ??= $tinted;
    $cover = \App\Domain\Artwork\LibraryItemArtwork::proxy(\App\Domain\Artwork\LibraryArtwork::coverUrl($album->images));
    // The enrichment (genres, release type and label, Last.fm listeners, the audio analysis of own files) only where the
    // list loaded it: `genreRelation`, the card metadata and `sound` (AlbumSound::attach).
    $details = $detail && $album->relationLoaded('metadata') ? $album->details() : null;
    $genres = $detail && $album->relationLoaded('genreRelation') ? array_slice($album->genres(), 0, 2) : [];
    $sound = $detail ? $album->getAttribute('sound') : null;
    $soundLine = $sound ? collect([
        $sound['bpm'] ? $sound['bpm'].' BPM' : null,
        $sound['loudness'] !== null ? number_format($sound['loudness'], 0).' LUFS' : null,
    ])->filter()->implode(' · ') : '';
    $meta = collect([
        $details['release_type'] ?? null ? ucfirst($details['release_type']) : null,
        $album->released_at?->format('Y'),
        $details['label'] ?? null,
        $showPlays && ($album->plays_count ?? 0) > 0 ? number_format($album->plays_count).' '.Str::plural('play', $album->plays_count) : null,
    ])->filter()->implode(' · ');
@endphp

<x-card compact flush class="group {{ $tinted ? 'tint w-36 shrink-0 snap-start sm:w-40 lg:w-44' : 'min-w-0 transition-colors hover:border-gray-300 dark:hover:border-stone-600' }}"
        style="{{ $tinted ? \App\Domain\Artwork\ArtworkTint::style($album->colors) : '' }}">
    <div class="relative aspect-square">
    <a href="{{ route('albums.show', $album) }}" class="block h-full">
        <x-artwork-thumb
            :src="$cover"
            :colors="$album->colors"
            :seed="$album->name.$album->id"
            icon="fa-solid fa-compact-disc"
            size="w-full h-full"
            rounded="rounded-none"
            class="text-3xl"
        />
        <div class="absolute left-1.5 bottom-1.5">
            <x-source-badges :dlna="(bool) ($album->on_dlna ?? false)" :spotify="(bool) ($album->on_spotify ?? false)" />
        </div>
        @if($album->favorited_at)
            <x-fav-badge />
        @endif
    </a>
    @if($quick)
        <x-quick-play :action="\App\Support\DeviceAction::template('albums.play', ['album' => $album])" />
    @endif
    </div>
    <div class="px-2.5 py-2">
        <a href="{{ route('albums.show', $album) }}" class="block text-[13px] font-medium leading-tight {{ $tinted ? '' : 'text-gray-900 dark:text-gray-100' }} truncate hover:underline" title="{{ $album->name }}">{{ $album->name }}</a>
        @if($album->artist)
            <a href="{{ route('artists.show', $album->artist) }}" class="block text-xs leading-tight {{ $tinted ? 'tint-muted' : 'text-gray-500' }} truncate hover:underline mt-0.5" title="{{ $album->artist->name }}">{{ $album->artist->name }}</a>
        @endif
        @if($meta !== '')
            <p class="text-[11px] leading-tight {{ $tinted ? 'tint-muted' : 'text-gray-400 dark:text-gray-600' }} truncate mt-0.5" title="{{ $meta }}">{{ $meta }}</p>
        @endif
        @if($genres)
            <div class="flex flex-wrap gap-1 mt-1.5">
                @foreach($genres as $genre)
                    <x-badge class="!text-[10px] !py-px max-w-full truncate {{ $tinted ? '!bg-black/10 dark:!bg-white/10 !text-inherit' : '' }}">{{ $genre }}</x-badge>
                @endforeach
            </div>
        @endif
        @if($soundLine !== '' || ($details['listeners'] ?? null) || ($details['rating'] ?? null))
            <p class="text-[10px] leading-tight text-gray-400 dark:text-gray-600 truncate mt-1.5">
                @if($soundLine !== '')<i class="fa-solid fa-wave-square mr-0.5"></i>{{ $soundLine }}@endif
                @if($details['rating'] ?? null)<span title="TheAudioDB rating">{{ $soundLine !== '' ? ' · ' : '' }}★ {{ $details['rating'] }}</span>@endif
                @if($details['listeners'] ?? null)<span title="Last.fm listeners">{{ ($soundLine !== '' || ($details['rating'] ?? null)) ? ' · ' : '' }}{{ \Illuminate\Support\Number::abbreviate($details['listeners'], maxPrecision: 1) }} listeners</span>@endif
            </p>
        @endif
    </div>
</x-card>
