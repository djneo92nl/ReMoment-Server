@props(['hide' => null])
{{-- `hide`: sm | md | lg, shows the column from that breakpoint up. --}}
@php($vis = ['sm' => 'hidden sm:table-cell', 'md' => 'hidden md:table-cell', 'lg' => 'hidden lg:table-cell'][$hide] ?? '')
<th {{ $attributes->merge(['class' => "px-4 py-3.5 text-left font-medium text-gray-500 dark:text-gray-400 $vis"]) }}>{{ $slot }}</th>
