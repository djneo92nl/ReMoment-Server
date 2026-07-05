@props(['size' => 'lg'])
@php
    $sizes = [
        'sm' => 'px-3 py-1.5 text-xs rounded-xl',
        'md' => 'px-4 py-2 text-sm rounded-xl',
        'lg' => 'px-5 py-2.5 text-sm rounded-2xl',
        'xl' => 'px-6 py-2.5 text-sm rounded-2xl',
    ];
    $cls = 'inline-flex items-center gap-2 font-medium ' . ($sizes[$size] ?? $sizes['lg'])
         . ' bg-gray-900 dark:bg-stone-700 text-white hover:bg-gray-700 dark:hover:bg-stone-600'
         . ' transition-colors disabled:opacity-50 disabled:cursor-not-allowed';
@endphp
@if($attributes->has('href'))
    <a {{ $attributes->merge(['class' => $cls]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => $cls]) }}>{{ $slot }}</button>
@endif
