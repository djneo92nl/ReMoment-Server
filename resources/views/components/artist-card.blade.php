@props(['artist'])

@php
    // The artist's own photo (TheAudioDB or Spotify) when known, else the cover of their most played album.
    $cover = $artist->coverAlbum();
    $raw = $artist->photoUrl() ?? \App\Domain\Artwork\LibraryArtwork::coverUrl($cover?->images);
    $src = \App\Domain\Artwork\LibraryItemArtwork::proxy($raw);
    $meta = $artist->albums_count.' '.Str::plural('album', $artist->albums_count)
        .($artist->plays_count > 0 ? ' · '.number_format($artist->plays_count).' '.Str::plural('play', $artist->plays_count) : '');
@endphp

<x-card compact flush :href="route('artists.show', $artist)" class="group min-w-0">
    <div class="relative aspect-square">
        <x-artwork-thumb :src="$src" :colors="$cover?->colors" :seed="$artist->name"
                         icon="fa-solid fa-microphone-lines" size="w-full h-full" rounded="rounded-none" class="text-3xl" />
        @if($artist->favorited_at)
            <x-fav-badge />
        @endif
    </div>
    <div class="px-2.5 py-2">
        <p class="text-[13px] font-medium text-gray-900 dark:text-gray-100 truncate leading-tight" title="{{ $artist->name }}">{{ $artist->name }}</p>
        <p class="text-[11px] text-gray-400 dark:text-gray-600 truncate mt-0.5">{{ $meta }}</p>
    </div>
</x-card>
