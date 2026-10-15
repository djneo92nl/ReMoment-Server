@props(['compact' => false, 'href' => null, 'flush' => false, 'title' => null, 'tag' => null])
{{-- The standard surface. `compact` = rounded-2xl (grid items), `flush` = clips children (lists/tables), `href` = a link card, `title` = heading inside the card, `tag` = root element (default div, a for href). Padding is the caller's: add p-5/p-6.
     The root tag is dynamic instead of an @if/@else: inside a Livewire view, a directive before the root makes Livewire's root-tag detection pick the wrong element. --}}
@php
    $cls = ($compact ? 'rounded-2xl' : 'rounded-3xl')
         . ' bg-white dark:bg-stone-900 border border-gray-200/70 dark:border-stone-800 shadow-sm'
         . ($flush ? ' overflow-hidden' : '')
         . ($href ? ' block transition-colors hover:border-gray-300 dark:hover:border-stone-700' : '');
    $tag ??= $href ? 'a' : 'div';
@endphp
<{{ $tag }} {{ $attributes->merge(['class' => $cls])->merge($href ? ['href' => $href] : []) }}>
    @if($title)
        <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-5">{{ $title }}</h2>
    @endif
    {{ $slot }}
</{{ $tag }}>
