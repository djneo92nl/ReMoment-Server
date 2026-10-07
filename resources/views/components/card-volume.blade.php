@props([
    'volume',
    // true/false shows a mute button for that state; null shows a plain speaker icon
    'muted' => null,
    'fill' => 'bg-gray-400 dark:bg-stone-500',
    'track' => '',
])

{{-- The volume row of a device card: speaker/mute icon, a slider that calls the component's setVolume(), the level. The
     component's `volume` property keeps it current ($wire.volume). --}}
<div x-data="{ vol: {{ (int) $volume }} }"
     x-init="$watch('$wire.volume', v => vol = v)"
     {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    @if($muted !== null)
        <button wire:click="toggleMute" title="{{ $muted ? 'Unmute' : 'Mute' }}"
                class="text-gray-400 dark:text-gray-600 hover:text-gray-700 dark:hover:text-gray-300 text-sm w-4 flex-shrink-0 transition-colors">
            <i class="fa-solid {{ $muted ? 'fa-volume-xmark' : 'fa-volume-high' }}"></i>
        </button>
    @else
        <i class="fa-solid fa-volume-high text-gray-400 dark:text-gray-600 w-4 flex-shrink-0"></i>
    @endif
    <div class="relative flex-1 {{ $track }}">
        <div class="h-1.5 bg-gray-200 dark:bg-stone-700 rounded-full overflow-hidden">
            <div class="h-full {{ $fill }} rounded-full" :style="'width: ' + vol + '%'"></div>
        </div>
        <input type="range" min="0" max="100" x-model="vol" @change="$wire.setVolume(vol)"
               class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
    </div>
    <span class="text-xs font-medium text-gray-500 dark:text-gray-400 w-6 text-right" x-text="vol"></span>
</div>
