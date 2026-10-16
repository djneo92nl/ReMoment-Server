@props(['label' => 'Options', 'icon' => 'ellipsis', 'button' => 'flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-stone-700 dark:hover:text-gray-200', 'active' => null])
{{-- A "…" button with a dropdown of x-menu.item entries. The panel is position:fixed from the button's rectangle, so a clipping parent (overflow-hidden card or hero) can't cut it off; it closes on scroll, Escape and outside clicks.
     `active`: an Alpine expression that highlights the button while true (an open details panel). --}}
<div x-data="{ open: false, top: 0, right: 0 }" @click.outside="open = false" @keydown.escape.window="open = false" @scroll.window="open = false" class="relative flex-none">
    <button type="button" class="{{ $button }}" title="{{ $label }}" aria-label="{{ $label }}" aria-haspopup="menu" :aria-expanded="open"
            @if($active) :class="(open || ({{ $active }})) && 'text-blue-500 dark:text-blue-400'" @else :class="open && 'text-blue-500 dark:text-blue-400'" @endif
            @click="const r = $el.getBoundingClientRect(); top = r.bottom + 4; right = window.innerWidth - r.right; open = !open">
        <i class="fa-solid fa-{{ $icon }} text-xs"></i>
    </button>
    <div x-show="open" x-cloak x-transition.opacity role="menu" @click="open = false"
         :style="`position:fixed;top:${top}px;right:${right}px;z-index:45`"
         class="min-w-52 rounded-xl border border-gray-200 bg-white py-1 text-sm text-gray-900 shadow-xl dark:border-stone-700 dark:bg-stone-800 dark:text-gray-100">
        {{ $slot }}
    </div>
</div>
