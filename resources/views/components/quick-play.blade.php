@props(['action', 'label' => 'Play'])
{{-- The round play button on a cover. With a player pinned it plays there (a POST to the item's play route, `$action` is its
     DeviceAction::template); without one it opens the player picker. Place it inside a `relative group` box: it shows on hover or focus,
     and always on touch screens. Not x-play-button, which renders a device picker per card. --}}
@php
    $pinned = app(\App\Support\SelectedDevice::class)->device();
    $btn = 'flex h-10 w-10 items-center justify-center rounded-full bg-white text-gray-900 shadow-lg ring-1 ring-black/5 transition hover:scale-105';
    $wrap = 'absolute bottom-2 right-2 opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100';
@endphp
@if($pinned)
    <form method="POST" action="{{ str_replace('{id}', $pinned->id, $action) }}" {{ $attributes->merge(['class' => $wrap]) }}>
        @csrf
        <button type="submit" class="{{ $btn }}" title="{{ $label }} on {{ $pinned->device_name }}" aria-label="{{ $label }} on {{ $pinned->device_name }}"><i class="fa-solid fa-play text-sm"></i></button>
    </form>
@else
    <div {{ $attributes->merge(['class' => $wrap]) }}>
        <button type="button" class="{{ $btn }}" @click="$dispatch('open-player-panel')" title="Choose a player to play on" aria-label="Choose a player to play on"><i class="fa-solid fa-play text-sm"></i></button>
    </div>
@endif
