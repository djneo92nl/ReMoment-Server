@props([
    'name',
    'title' => 'Play on device',
    'description' => null,
    'devices',
    'actionTemplate',
    'elsewhere' => true,
    'wrapperClass' => '',
])

{{--
    Plays on the player pinned in the sticky bar when it is one of $devices
    (and can therefore play this item); otherwise, or via the chevron, the
    device picker opens. The button's content is the slot, its look the attributes.
--}}
@php
    $pinned = app(\App\Support\SelectedDevice::class)->device();
    $direct = $pinned !== null && $devices->contains('id', $pinned->id);
@endphp

@if($direct)
    <form method="POST" action="{{ str_replace('{id}', $pinned->id, $actionTemplate) }}" class="flex items-center gap-1 {{ $wrapperClass }}">
        @csrf
        <button type="submit" title="Play on {{ $pinned->device_name }}" {{ $attributes }}>{{ $slot }}</button>
        @if($elsewhere)
            <button type="button" @click="$dispatch('open-modal', '{{ $name }}')" title="Play on another device"
                    class="w-8 h-8 flex-shrink-0 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors">
                <i class="fa-solid fa-chevron-down text-xs"></i>
            </button>
        @endif
    </form>
@else
    <button type="button" @click="$dispatch('open-modal', '{{ $name }}')" {{ $attributes }}>{{ $slot }}</button>
@endif

<x-device-picker :name="$name" :title="$title" :description="$description" :devices="$devices" :action-template="$actionTemplate" />
