@props(['size' => 'lg'])
@php
    $sizes = [
        'sm' => 'px-3 py-1.5 text-xs rounded-xl',
        'md' => 'px-4 py-2 text-sm rounded-xl',
        'lg' => 'px-5 py-2.5 text-sm rounded-2xl',
        'xl' => 'px-6 py-2.5 text-sm rounded-2xl',
    ];
    $cls = 'inline-flex items-center gap-2 font-medium ' . ($sizes[$size] ?? $sizes['lg'])
         . ' bg-white dark:bg-stone-800 text-gray-700 dark:text-gray-300'
         . ' border border-gray-200 dark:border-stone-700'
         . ' hover:bg-gray-50 dark:hover:bg-stone-700 transition-colors disabled:opacity-25';
@endphp
@if($attributes->has('href'))
    <a {{ $attributes->merge(['class' => $cls]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $cls]) }}>{{ $slot }}</button>
@endif
