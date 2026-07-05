@props(['size' => 'md'])
@php
    $sizes = [
        'sm' => 'px-3 py-1.5 text-xs rounded-xl',
        'md' => 'px-4 py-2 text-sm rounded-xl',
        'lg' => 'px-5 py-2.5 text-sm rounded-2xl',
    ];
    $cls = 'inline-flex items-center gap-2 font-medium ' . ($sizes[$size] ?? $sizes['md'])
         . ' bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400'
         . ' hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors';
@endphp
@if($attributes->has('href'))
    <a {{ $attributes->merge(['class' => $cls]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => $cls]) }}>{{ $slot }}</button>
@endif
