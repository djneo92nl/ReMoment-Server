@props(['href', 'icon', 'active' => false, 'sub' => false])
{{-- One entry of the main navigation (sidebar and mobile drawer). `sub` is the smaller indented level. --}}
@php
    $cls = $sub
        ? 'flex items-center gap-3 px-5 py-2.5 rounded-xl text-sm transition-colors '
            . ($active ? 'text-gray-900 dark:text-gray-100 font-medium' : 'text-gray-500 hover:text-gray-800 dark:hover:text-gray-300')
        : 'flex items-center gap-3 px-5 py-3.5 rounded-xl transition-colors '
            . ($active ? 'bg-gray-100 dark:bg-stone-800 font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-stone-800/50');
    $iconCls = ($sub ? 'w-4 text-xs ' : 'w-5 ')
        . ($active ? 'text-gray-700 dark:text-gray-300' : ($sub ? 'text-gray-300 dark:text-stone-700' : ''));
@endphp
<a href="{{ $href }}" @click="mobileMenuOpen = false" @if($active) aria-current="page" @endif class="{{ $cls }}">
    <i class="fa-solid fa-{{ $icon }} {{ $iconCls }}"></i>{{ $slot }}
</a>
