@props(['album', 'showPlays' => false])

@php
    $rawCover = \App\Domain\Artwork\LibraryArtwork::coverUrl($album->images);
    $cover = \App\Domain\Artwork\LibraryItemArtwork::webUrl(\App\Domain\Artwork\LibraryItemArtwork::forUrl($rawCover)['proxy_320'] ?? null) ?? $rawCover;
    $meta = collect([
        $album->released_at?->format('Y'),
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
        @if($album->favorited_at)
            <span class="absolute top-1.5 right-1.5 w-5 h-5 rounded-md bg-black/55 text-amber-300 text-[10px] flex items-center justify-center">
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
            <p class="text-[11px] leading-tight text-gray-400 dark:text-gray-600 truncate mt-0.5">{{ $meta }}</p>
        @endif
    </div>
</div>
