@props(['type' => 'info', 'icon' => null])
{{-- Renders nothing without a message. Flash and inline messages: success | error | warning | info. Put an action (button/link) in the `action` slot. --}}
@php
    $styles = [
        'success' => ['bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300', 'circle-check'],
        'error'   => ['bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300', 'circle-exclamation'],
        'warning' => ['bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300', 'triangle-exclamation'],
        'info'    => ['bg-sky-50 dark:bg-sky-900/20 border-sky-200 dark:border-sky-800 text-sky-800 dark:text-sky-300', 'circle-info'],
    ];
    [$cls, $defaultIcon] = $styles[$type] ?? $styles['info'];
@endphp
@if(! $slot->isEmpty())
<div role="{{ $type === 'error' ? 'alert' : 'status' }}"
     {{ $attributes->merge(['class' => "flex items-center gap-3 rounded-2xl border px-6 py-4 text-sm $cls"]) }}>
    <i class="fa-solid fa-{{ $icon ?? $defaultIcon }} flex-shrink-0"></i>
    <div class="min-w-0 flex-1">{{ $slot }}</div>
    @isset($action)
        <div class="flex-shrink-0">{{ $action }}</div>
    @endisset
</div>
@endif
