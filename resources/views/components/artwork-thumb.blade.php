@props([
    'src' => null,
    'colors' => null,
    'seed' => '',
    'icon' => 'fa-solid fa-music',
    'size' => 'w-12 h-12',
    'rounded' => 'rounded-xl',
    'proxy' => 320,
])

@php
    // Never show the original from DLNA/Spotify/radio: anything that is not already one of our
    // /storage proxies goes through the artwork pipeline (null, so the placeholder, until processed).
    if ($src && !str_contains($src, '/storage/')) {
        $src = \App\Domain\Artwork\LibraryItemArtwork::proxy($src, $proxy);
    }
    $colors = $colors ?? [];
    if (count($colors) < 2) {
        $colors = \App\Support\ColorHash::gradient($seed !== '' ? $seed : 'artwork');
    }
@endphp

<div {{ $attributes->merge(['class' => "{$size} {$rounded} overflow-hidden flex-shrink-0"]) }}
     style="background: linear-gradient(135deg, {{ $colors[0] }}, {{ $colors[1] }})">
    @if($src)
        <img src="{{ $src }}" alt="" class="w-full h-full object-cover">
    @else
        <div class="w-full h-full flex items-center justify-center">
            <i class="{{ $icon }} text-white/60"></i>
        </div>
    @endif
</div>
