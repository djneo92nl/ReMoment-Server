@props(['deviceId', 'position', 'duration', 'playing' => false, 'seekable' => false, 'size' => 'md'])

{{-- Progress bar that advances client-side between Livewire renders (see
     progressTicker in public/js/remoment-live.js). Keyed on the server
     position/state so each fresh render re-seeds the ticker. --}}
<div wire:key="progress-{{ $deviceId }}-{{ $position }}-{{ $playing ? 1 : 0 }}"
     x-data="progressTicker({{ $deviceId }}, {{ $position }}, {{ $duration }}, {{ $playing ? 'true' : 'false' }})">
    <div class="flex justify-between {{ $size === 'sm' ? 'text-xs text-gray-400 dark:text-gray-600 mb-1.5' : 'text-sm text-gray-500 mb-2' }}">
        <span x-text="elapsed">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($position) }}</span>
        <span>{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($duration) }}</span>
    </div>
    <div @if($seekable) @click.stop="seekTo($event)" title="Click to seek" @endif
         class="{{ $seekable ? 'cursor-pointer py-1.5 -my-1.5' : '' }}">
        <div class="h-1.5 bg-gray-200 dark:bg-stone-700 rounded-full overflow-hidden">
            <div class="h-full bg-gradient-to-r from-red-500 to-rose-600 rounded-full transition-[width] duration-1000 ease-linear"
                 :style="'width: ' + pct + '%'"
                 style="width: {{ $duration > 0 ? min(100, (int) ($position / $duration * 100)) : 0 }}%"></div>
        </div>
    </div>
</div>
