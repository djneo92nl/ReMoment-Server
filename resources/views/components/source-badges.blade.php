@props(['dlna' => false, 'spotify' => false, 'labels' => false, 'light' => false])

{{-- Inline colors: Tailwind's CDN doesn't generate every color class used only in components. --}}
@php
    $bg = $light ? 'background:rgba(120,120,120,.15)' : 'background:rgba(0,0,0,.6)';
    $plain = $light ? 'color:#6b7280' : 'color:#fff';
    $green = $light ? 'color:#059669' : 'color:#34d399';
@endphp

@if($dlna || $spotify)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1']) }}>
        @if($dlna)
            <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] leading-none" style="{{ $bg }};{{ $plain }}" title="In the DLNA library">
                <i class="fa-solid fa-server"></i>@if($labels)<span>DLNA</span>@endif
            </span>
        @endif
        @if($spotify)
            <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] leading-none" style="{{ $bg }};{{ $green }}" title="From Spotify">
                <i class="fa-brands fa-spotify"></i>@if($labels)<span>Spotify</span>@endif
            </span>
        @endif
    </span>
@endif
