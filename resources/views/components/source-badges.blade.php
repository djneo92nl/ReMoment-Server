@props(['dlna' => false, 'spotify' => false, 'labels' => false, 'light' => false])

{{-- `light`: on a light surface; default: on a cover (dark chip, white text). --}}
@php
    $chip = $light ? 'bg-gray-500/15' : 'bg-black/60';
    $plain = $light ? 'text-gray-500' : 'text-white';
    $green = $light ? 'text-emerald-600' : 'text-emerald-400';
@endphp

@if($dlna || $spotify)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1']) }}>
        @if($dlna)
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] leading-none {{ $chip }} {{ $plain }}" title="In the DLNA library">
                <i class="fa-solid fa-server"></i>@if($labels)<span>DLNA</span>@endif
            </span>
        @endif
        @if($spotify)
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] leading-none {{ $chip }} {{ $green }}" title="From Spotify">
                <i class="fa-brands fa-spotify"></i>@if($labels)<span>Spotify</span>@endif
            </span>
        @endif
    </span>
@endif
