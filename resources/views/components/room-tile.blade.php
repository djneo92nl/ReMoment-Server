@props([
    'id',
    'name',
    // 'host' | 'member' | 'available'
    'role' => 'available',
    // 0-100, or false/null when the room has no volume to show
    'volume' => null,
    // Livewire method called as method(id, volume) when the tile is dragged
    'volumeMethod' => 'setMemberVolume',
    // Full Livewire call, e.g. removeListener(4): the check of a member, or a tap on an available tile
    'toggle' => null,
    'closeOnToggle' => false,
])

{{-- A room as in the B&O app: the tile is its own volume slider (the grey fill), a check says it is in the session. Geometry is inline on purpose (lazy Tailwind classes). --}}
@php
    $hasVolume = $role !== 'available' && $volume !== null && $volume !== false;
    $badge = 'position:absolute;top:.6rem;right:.6rem;z-index:3;width:1.7rem;height:1.7rem;border-radius:9999px;display:flex;align-items:center;justify-content:center;border:0;padding:0;';
@endphp

<div x-data="{ vol: {{ (int) $volume }} }" {{ $attributes->merge(['class' => 'bg-gray-100 dark:bg-stone-800 text-gray-900 dark:text-gray-100']) }}
     style="position:relative;overflow:hidden;min-height:5.5rem;border-radius:1rem;{{ $role === 'available' ? 'opacity:.85;' : '' }}">

    @if($hasVolume)
        <div :style="{ width: vol + '%' }" style="position:absolute;top:0;bottom:0;left:0;width:{{ (int) $volume }}%;background:rgba(75,85,99,.32)"></div>
        <input type="range" min="0" max="100" x-model="vol" @change="$wire.{{ $volumeMethod }}({{ $id }}, parseInt(vol))"
               title="{{ $name }} volume" style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:ew-resize;z-index:1;margin:0">
    @elseif($role === 'available' && $toggle)
        <button type="button" wire:click="{{ $toggle }}" wire:loading.attr="disabled" @if($closeOnToggle) @click="$dispatch('close')" @endif
                title="Add {{ $name }}" style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;z-index:1;border:0;padding:0"></button>
    @endif

    <i class="fa-solid {{ $role === 'host' ? 'fa-tower-broadcast' : 'fa-volume-high' }} text-gray-500 dark:text-gray-400"
       style="position:absolute;top:.8rem;left:.9rem;z-index:2;pointer-events:none"></i>
    <span style="position:absolute;left:.9rem;right:.9rem;bottom:.7rem;z-index:2;pointer-events:none;font-weight:600;line-height:1.2" class="truncate">{{ $name }}</span>

    @if($role === 'host')
        <span style="{{ $badge }}background:#fbbf24;pointer-events:none" title="Hosts this session"><i class="fa-solid fa-layer-group" style="font-size:.75rem;color:#1f2937"></i></span>
    @elseif($role === 'member')
        @if($toggle)
            <button type="button" wire:click="{{ $toggle }}" wire:loading.attr="disabled" title="Remove {{ $name }} from this session"
                    style="{{ $badge }}background:#fbbf24;cursor:pointer"><i class="fa-solid fa-check" style="font-size:.75rem;color:#1f2937"></i></button>
        @else
            <span style="{{ $badge }}background:#fbbf24;pointer-events:none"><i class="fa-solid fa-check" style="font-size:.75rem;color:#1f2937"></i></span>
        @endif
    @else
        <span class="border border-gray-300 dark:border-stone-600" style="{{ $badge }}pointer-events:none"></span>
    @endif
</div>
