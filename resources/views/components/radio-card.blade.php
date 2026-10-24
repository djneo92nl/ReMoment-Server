@props(['station', 'art' => null, 'subtitle' => null, 'live' => false])
{{-- A radio station tile for the /radio shelves: tinted by its logo like the media card, with quick play. `live` shows what the station
     plays right now (a polling Livewire line, so use it for a handful of tiles only); otherwise `subtitle` is plain text. --}}
<x-card compact flush class="tint group w-36 shrink-0 snap-start sm:w-40 lg:w-44" style="{{ \App\Domain\Artwork\ArtworkTint::style($art['colors'] ?? null) }}">
    <div class="relative aspect-square">
        <a href="{{ route('radio.show', $station) }}" class="block h-full">
            <x-artwork-thumb :src="$art['proxy_320'] ?? null" :colors="$art['colors'] ?? null" :seed="$station->name" icon="fa-solid fa-radio" :label="$station->initials()" data-label-class="text-4xl" size="w-full h-full" rounded="rounded-none" class="text-3xl" />
        </a>
        <x-quick-play :action="\App\Support\DeviceAction::template('radio.play', ['radio' => $station])" />
        @if($station->getMeta('stream_status') === 'dead')
            <span class="absolute left-2 top-2 rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-medium text-white">No signal</span>
        @endif
    </div>
    <div class="px-3 py-2.5">
        <a href="{{ route('radio.show', $station) }}" class="block truncate text-[13px] font-medium leading-tight hover:underline" title="{{ $station->name }}">{{ $station->name }}</a>
        @if($live)
            <livewire:radio-now-playing :station-id="$station->id" :fallback="$subtitle" tinted :key="'now-'.$station->id" />
        @elseif($subtitle)
            <p class="tint-muted mt-0.5 truncate text-xs leading-tight">{{ $subtitle }}</p>
        @endif
    </div>
</x-card>
