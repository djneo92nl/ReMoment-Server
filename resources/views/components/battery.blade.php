@props(['deviceId'])

@php
    $battery = \App\Domain\Device\Cache\Battery::get((int) $deviceId);
    $level = $battery?->level;
    $icon = match (true) {
        $level === null => null,
        $level >= 88 => 'fa-battery-full',
        $level >= 63 => 'fa-battery-three-quarters',
        $level >= 38 => 'fa-battery-half',
        $level >= 13 => 'fa-battery-quarter',
        default => 'fa-battery-empty',
    };
    $color = match (true) {
        $battery?->charging => 'text-emerald-500',
        $level !== null && $level < 15 => 'text-red-500',
        $level !== null && $level < 30 => 'text-amber-500',
        default => 'text-gray-500 dark:text-gray-400',
    };
@endphp

@if($battery)
    <span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 text-sm {$color}"]) }}
          title="Battery {{ $level }}%{{ $battery->charging ? ', charging' : '' }}">
        <i class="fa-solid {{ $icon }}"></i>
        @if($battery->charging)<i class="fa-solid fa-bolt text-xs"></i>@endif
        <span>{{ $level }}%</span>
    </span>
@endif
