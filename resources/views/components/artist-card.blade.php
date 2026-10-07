@props(['artist'])

@php
    // The artist's own photo (TheAudioDB or Spotify) when known, else the cover of their most played album.
    $cover = $artist->coverAlbum();
    $raw = $artist->photoUrl() ?? \App\Domain\Artwork\LibraryArtwork::coverUrl($cover?->images);
    $src = \App\Domain\Artwork\LibraryItemArtwork::proxy($raw);
    $meta = $artist->albums_count.' '.Str::plural('album', $artist->albums_count)
        .($artist->plays_count > 0 ? ' · '.number_format($artist->plays_count).' '.Str::plural('play', $artist->plays_count) : '');
@endphp

<a href="{{ route('artists.show', $artist) }}" class="group block min-w-0 rounded-xl bg-white dark:bg-stone-900 border border-gray-200/70 dark:border-stone-800/80 hover:border-gray-300 dark:hover:border-stone-600 overflow-hidden transition-colors">
    <div class="relative" style="aspect-ratio:1/1">
        <x-artwork-thumb :src="$src" :colors="$cover?->colors" :seed="$artist->name"
                         icon="fa-solid fa-microphone-lines" size="w-full h-full" rounded="rounded-none" class="text-3xl" />
        @if($artist->favorited_at)
            <span class="flex items-center justify-center text-[10px]" style="position:absolute;top:6px;right:6px;width:20px;height:20px;border-radius:6px;background:rgba(0,0,0,.6);color:#fcd34d">
                <i class="fa-solid fa-star"></i>
            </span>
        @endif
    </div>
    <div class="px-2.5 py-2">
        <p class="text-[13px] font-medium text-gray-900 dark:text-gray-100 truncate leading-tight" title="{{ $artist->name }}">{{ $artist->name }}</p>
        <p class="text-[11px] text-gray-400 dark:text-gray-600 truncate mt-0.5">{{ $meta }}</p>
    </div>
</a>
