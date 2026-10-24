@props(['station', 'art' => null, 'live' => false, 'subtitle' => null])
{{-- One station in the /radio list: logo, name and genre. Favourite and play are plain icons that show on hover/focus (always on touch). --}}
@php $shown = 'opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100'; @endphp
<div class="group flex items-center gap-3 rounded-2xl px-2 py-2 hover:bg-gray-100 dark:hover:bg-stone-900">
    <a href="{{ route('radio.show', $station) }}" class="shrink-0">
        <x-artwork-thumb :src="$art['proxy_120'] ?? null" :colors="$art['colors'] ?? null" :seed="$station->name" icon="fa-solid fa-radio" :label="$station->initials()" data-label-class="text-sm" size="h-11 w-11" proxy="120" />
    </a>
    <div class="min-w-0 flex-1">
        <a href="{{ route('radio.show', $station) }}" class="block truncate text-sm font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $station->name }}</a>
        @if($live)
            <livewire:radio-now-playing :station-id="$station->id" :fallback="$subtitle" :key="'row-now-'.$station->id" />
        @else
            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                @if($station->getMeta('stream_status') === 'dead')
                    <span class="text-red-600 dark:text-red-400">No signal</span>{{ ($subtitle ?? $station->genre) ? ' · ' : '' }}
                @endif{{ $subtitle ?? $station->genre }}
            </p>
        @endif
    </div>
    <form method="POST" action="{{ route('radio.favorite', $station) }}" class="{{ $shown }}">
        @csrf
        <button type="submit" aria-label="{{ $station->favorited_at ? 'Remove from favourites' : 'Add to favourites' }}"
                class="flex h-9 w-9 items-center justify-center text-gray-400 transition hover:text-rose-500 dark:text-gray-500"><i class="fa-regular fa-heart text-sm"></i></button>
    </form>
    <div class="{{ $shown }}"><x-radio-play :station="$station" tone="plain" /></div>
</div>
