@props(['compact' => false, 'href' => null, 'flush' => false])
{{-- The standard surface. `compact` = rounded-2xl (grid items), `flush` = clips children (lists/tables), `href` = a link card. Padding is the caller's: add p-5/p-6. --}}
@php
    $cls = ($compact ? 'rounded-2xl' : 'rounded-3xl')
         . ' bg-white dark:bg-stone-900 border border-gray-200/70 dark:border-stone-800 shadow-sm'
         . ($flush ? ' overflow-hidden' : '')
         . ($href ? ' block transition-colors hover:border-gray-300 dark:hover:border-stone-700' : '');
@endphp
@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $cls]) }}>{{ $slot }}</a>
@else
    <div {{ $attributes->merge(['class' => $cls]) }}>{{ $slot }}</div>
@endif
