@props(['album', 'showPlays' => false, 'detail' => false])

@php
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

<div class="group block min-w-0 rounded-xl bg-white dark:bg-stone-900 border border-gray-200/70 dark:border-stone-800/80 hover:border-gray-300 dark:hover:border-stone-600 overflow-hidden transition-colors">
    <a href="{{ route('albums.show', $album) }}" class="block relative" style="aspect-ratio:1/1">
        <x-artwork-thumb
            :src="$cover"
            :colors="$album->colors"
            :seed="$album->name.$album->id"
            icon="fa-solid fa-compact-disc"
            size="w-full h-full"
            rounded="rounded-none"
            class="text-3xl"
        />
        <div style="position:absolute;left:6px;bottom:6px">
            <x-source-badges :dlna="(bool) ($album->on_dlna ?? false)" :spotify="(bool) ($album->on_spotify ?? false)" />
        </div>
        @if($album->favorited_at)
            <span class="flex items-center justify-center text-[10px]" style="position:absolute;top:6px;right:6px;width:20px;height:20px;border-radius:6px;background:rgba(0,0,0,.6);color:#fcd34d">
                <i class="fa-solid fa-star"></i>
            </span>
        @endif
    </a>
    <div class="px-2.5 py-2">
        <a href="{{ route('albums.show', $album) }}" class="block text-[13px] font-medium leading-tight text-gray-900 dark:text-gray-100 truncate hover:underline" title="{{ $album->name }}">{{ $album->name }}</a>
        @if($album->artist)
            <a href="{{ route('artists.show', $album->artist) }}" class="block text-xs leading-tight text-gray-500 truncate hover:underline mt-0.5" title="{{ $album->artist->name }}">{{ $album->artist->name }}</a>
        @endif
        @if($meta !== '')
            <p class="text-[11px] leading-tight text-gray-400 dark:text-gray-600 truncate mt-0.5" title="{{ $meta }}">{{ $meta }}</p>
        @endif
        @if($genres)
            <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:6px">
                @foreach($genres as $genre)
                    <span class="text-[10px] bg-gray-100 dark:bg-stone-800 text-gray-500 dark:text-gray-400 truncate" style="max-width:100%;padding:1px 8px;border-radius:9999px">{{ $genre }}</span>
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
</div>
