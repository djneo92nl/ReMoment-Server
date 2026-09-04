@props(['title' => null])

<div>
    @if($title)
        <h2 class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-2 px-1">{{ $title }}</h2>
    @endif
    <div {{ $attributes->merge(['class' => 'bg-white dark:bg-stone-900 rounded-2xl shadow-sm border border-gray-200/70 dark:border-stone-800/80 divide-y divide-gray-100 dark:divide-stone-800 overflow-hidden']) }}>
        {{ $slot }}
    </div>
</div>
