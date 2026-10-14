@props(['variant' => 'neutral'])
{{-- Small pill: neutral | outline | success | warning | danger | info. --}}
@php
    $variants = [
        'neutral' => 'bg-gray-100 dark:bg-stone-800 text-gray-600 dark:text-gray-400',
        'outline' => 'border border-gray-200 dark:border-stone-700 text-gray-500',
        'success' => 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300',
        'warning' => 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300',
        'danger'  => 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300',
        'info'    => 'bg-sky-50 dark:bg-sky-900/20 text-sky-700 dark:text-sky-300',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium ' . ($variants[$variant] ?? $variants['neutral'])]) }}>{{ $slot }}</span>
