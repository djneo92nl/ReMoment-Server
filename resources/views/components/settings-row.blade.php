@props(['href', 'icon', 'iconBg' => 'bg-gray-100 dark:bg-stone-800', 'iconColor' => 'text-gray-500', 'label'])

<a href="{{ $href }}"
   {{ $attributes->merge(['class' => 'flex items-center gap-3.5 px-5 py-3.5 hover:bg-gray-50/70 dark:hover:bg-stone-800/40 transition-colors']) }}>
    <div class="w-8 h-8 rounded-lg {{ $iconBg }} flex items-center justify-center flex-shrink-0">
        <i class="{{ $icon }} {{ $iconColor }} text-sm"></i>
    </div>
    <div class="flex-1 min-w-0 text-[15px] text-gray-900 dark:text-gray-100">{{ $label }}</div>
    @if($slot->isNotEmpty())
        <div class="text-sm text-gray-400 dark:text-gray-600 flex items-center gap-1">
            {{ $slot }}
        </div>
    @endif
    <i class="fa-solid fa-chevron-right text-gray-300 dark:text-stone-700 text-xs"></i>
</a>
