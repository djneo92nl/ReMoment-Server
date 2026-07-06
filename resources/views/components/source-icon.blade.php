@props(['source' => null, 'size' => 'text-xs'])

@php
    [$iconClass, $colorClass] = match(strtolower($source ?? '')) {
        'spotify' => ['fa-brands fa-spotify', 'text-emerald-500'],
        'dlna' => ['fa-solid fa-server', 'text-gray-400 dark:text-gray-500'],
        'local' => ['fa-solid fa-house', 'text-gray-400 dark:text-gray-500'],
        default => ['fa-solid fa-music', 'text-gray-300 dark:text-stone-600'],
    };
@endphp

<i {{ $attributes->merge(['class' => "{$iconClass} {$colorClass} {$size}"]) }} title="{{ ucfirst($source ?? 'Unknown') }}"></i>
