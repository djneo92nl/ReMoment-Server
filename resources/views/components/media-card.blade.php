@props(['href', 'src' => null, 'title', 'subtitle' => null, 'colors' => null, 'seed' => '', 'icon' => 'fa-solid fa-music', 'play' => null, 'playLabel' => 'Play', 'round' => false])
{{-- A tile for anything that is not an album: a playlist, a radio station, a mixed "recently played" entry. Cover on top, a footer tinted by the
     cover's colours (App\Domain\Artwork\ArtworkTint), an optional quick-play. `src` is already a processed proxy URL (or null for the placeholder). --}}
<x-card compact flush class="tint group w-40 shrink-0 snap-start" style="{{ \App\Domain\Artwork\ArtworkTint::style($colors) }}">
    <div class="relative aspect-square">
        <a href="{{ $href }}" class="block h-full">
            <x-artwork-thumb :src="$src" :colors="$colors" :seed="$seed ?: $title" :icon="$icon" size="w-full h-full" :rounded="$round ? 'rounded-full' : 'rounded-none'" class="text-3xl" />
        </a>
        @if($play)
            <x-quick-play :action="$play" :label="$playLabel" />
        @endif
    </div>
    <div class="px-3 py-2.5">
        <a href="{{ $href }}" class="block truncate text-[13px] font-medium leading-tight hover:underline" title="{{ $title }}">{{ $title }}</a>
        @if($subtitle)
            <p class="tint-muted mt-0.5 truncate text-xs leading-tight" title="{{ $subtitle }}">{{ $subtitle }}</p>
        @endif
    </div>
</x-card>
