@props(['station', 'tone' => 'solid'])
{{-- Play a station on the pinned player, or open the player panel while none is pinned. `solid`/`light` are round buttons (on tiles),
     `plain` and `dark` (for dark panels) are just the icon, for lists. --}}
@php
    $pinned = app(\App\Support\SelectedDevice::class)->device();
    $class = match ($tone) {
        'plain' => 'flex h-9 w-9 items-center justify-center rounded-full text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white',
        
        'light' => 'flex h-10 w-10 items-center justify-center rounded-full bg-white text-gray-900 transition hover:scale-105',
        default => 'flex h-10 w-10 items-center justify-center rounded-full bg-gray-900 text-white transition hover:scale-105 dark:bg-stone-700',
    };
@endphp
@if($pinned)
    <form method="POST" action="{{ route('radio.play', [$station, $pinned]) }}">
        @csrf
        <button type="submit" class="{{ $class }}" aria-label="Play {{ $station->name }} on {{ $pinned->device_name }}" title="Play on {{ $pinned->device_name }}"><i class="fa-solid fa-play text-xs"></i></button>
    </form>
@else
    <button type="button" @click="$dispatch('open-player-panel')" class="{{ $class }}" aria-label="Choose a player to play on" title="Choose a player to play on"><i class="fa-solid fa-play text-xs"></i></button>
@endif
