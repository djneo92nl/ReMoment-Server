@props(['genre', 'albums' => null])
{{-- A genre as a tinted tile (the colours its covers share, GenreColors), with its album count. --}}
<a href="{{ route('genres.show', $genre->slug) }}"
   class="tint group flex h-24 w-40 shrink-0 sm:w-44 lg:w-48 snap-start flex-col justify-between rounded-2xl p-3.5 transition hover:brightness-110"
   style="{{ \App\Domain\Artwork\ArtworkTint::style($genre->colors) }}">
    <span class="line-clamp-2 text-base font-semibold leading-tight">{{ $genre->name }}</span>
    <span class="tint-muted text-xs">
        @if($albums !== null)
            {{ number_format($albums) }} {{ Str::plural('album', $albums) }}
        @else
            {{ number_format($genre->artists) }} {{ Str::plural('artist', $genre->artists) }}
        @endif
    </span>
</a>
