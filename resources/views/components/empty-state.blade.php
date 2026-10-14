@props(['icon' => 'inbox', 'title' => null, 'text' => null, 'bare' => false])
{{-- Nothing here yet. Wraps itself in a card; `bare` when it already sits inside one. The default slot is the call to action. --}}
@php($inner = 'flex flex-col items-center justify-center text-center px-8 py-16')
@if($bare)
<div {{ $attributes->merge(['class' => $inner]) }}>
@else
<x-card {{ $attributes->merge(['class' => $inner]) }}>
@endif
    <div class="w-16 h-16 mb-4 rounded-2xl bg-gray-100 dark:bg-stone-800 flex items-center justify-center">
        <i class="fa-solid fa-{{ $icon }} text-2xl text-gray-300 dark:text-stone-600"></i>
    </div>
    @if($title)
        <p class="mb-1 font-medium text-gray-700 dark:text-gray-300">{{ $title }}</p>
    @endif
    @if($text)
        <p class="text-sm text-gray-500">{{ $text }}</p>
    @endif
    @if(! $slot->isEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
@if($bare)
</div>
@else
</x-card>
@endif
