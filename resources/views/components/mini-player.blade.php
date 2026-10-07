@props([
    'device',
    // Livewire method called as method(deviceId, volume) when the slider is moved
    'volumeMethod' => null,
    // POST url that picks this device (the header becomes a button)
    'selectUrl' => null,
    'selectMethod' => 'POST',
    'selected' => false,
    'tag' => null,
    'tagColor' => '#0369a1',
    // multiroom rows: true = in the session (yellow check), false = can be added (empty circle), null = no control
    'inSession' => null,
    // Full Livewire call run when the check/circle is tapped, e.g. removeListener(4)
    'toggle' => null,
    'closeOnToggle' => false,
])

{{-- A small player in the Sonos style: name and battery, what is playing, a volume slider. Geometry is inline on purpose (lazy Tailwind classes). --}}
@php
    $state = \App\Domain\Device\DeviceCache::isListenerRunning($device->id) ? $device->state : \App\Domain\Device\State::Unreachable;
    $isPlaying = $state === \App\Domain\Device\State::Playing;
    $nowPlaying = \App\Domain\Device\DeviceCache::getNowPlaying($device->id);
    $artwork = $nowPlaying ? \App\Domain\Artwork\NowPlayingArtwork::resolve($nowPlaying) : null;
    $thumb = $artwork['proxy_120'] ?? $artwork['proxy_320'] ?? null;
    if ($thumb && ($at = strpos($thumb, '/storage/')) !== false) {
        $thumb = substr($thumb, $at);
    }
    $track = $nowPlaying?->track;
    $radio = $nowPlaying?->radio;
    $title = $track?->name ?? $radio?->name ?? $nowPlaying?->source?->name ?? match ($state) {
        \App\Domain\Device\State::Standby => 'Standby',
        \App\Domain\Device\State::Unreachable => 'Unreachable',
        default => 'No content',
    };
    $subtitle = $track ? collect([$track->artist?->name, $nowPlaying->album?->name])->filter()->implode(' · ') : ($radio ? 'Radio' : '');
    $volume = \App\Domain\Device\Cache\Volume::getVolume($device->id);
    $canVolume = $volumeMethod && in_array('volume_control', \App\Domain\Device\DeviceCapabilities::for($device), true) && $volume !== false;
@endphp

<div {{ $attributes->merge(['class' => 'rounded-2xl bg-gray-100 dark:bg-stone-800 text-gray-900 dark:text-gray-100']) }}
     style="padding:.85rem 1rem;{{ $selected ? 'box-shadow:0 0 0 2px #6366f1;' : '' }}{{ $state === \App\Domain\Device\State::Unreachable ? 'opacity:.55;' : '' }}">

    @if($selectUrl)
        @php
            $useMethod = strtoupper($selectMethod);
            $formMethod = in_array($useMethod, ['GET', 'POST']) ? $useMethod : 'POST';
        @endphp
        <form method="{{ $formMethod }}" action="{{ $selectUrl }}">
            @csrf
            @if($useMethod !== 'GET' && $useMethod !== 'POST')
                @method($useMethod)
            @endif
            <button type="submit" style="display:block;width:100%;text-align:left;background:none;border:0;padding:0;cursor:pointer;color:inherit">
                @include('components.partials.mini-player-head')
            </button>
        </form>
    @else
        @include('components.partials.mini-player-head')
    @endif

    @if($canVolume)
        <div x-data="{ vol: {{ (int) $volume }} }" style="display:flex;align-items:center;gap:.6rem;margin-top:.7rem">
            <i class="fa-solid {{ (int) $volume < 35 ? 'fa-volume-low' : 'fa-volume-high' }} text-gray-500 dark:text-gray-400" style="width:1.1rem;text-align:center;font-size:.85rem"></i>
            <div style="position:relative;flex:1;height:1.5rem;display:flex;align-items:center">
                <div style="width:100%;height:.65rem;border-radius:9999px;overflow:hidden;background:rgba(107,114,128,.3)">
                    <div :style="{ width: vol + '%' }" style="height:100%;border-radius:9999px;background:currentColor;width:{{ (int) $volume }}%"></div>
                </div>
                <input type="range" min="0" max="100" x-model="vol" @change="$wire.{{ $volumeMethod }}({{ $device->id }}, parseInt(vol))"
                       style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer">
            </div>
            <span class="text-gray-500 dark:text-gray-400" style="width:1.6rem;text-align:right;font-size:.8rem" x-text="vol">{{ (int) $volume }}</span>
        </div>
    @endif
</div>
