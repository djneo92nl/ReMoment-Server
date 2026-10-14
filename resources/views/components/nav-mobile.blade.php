{{-- Below `md`: the sticky top bar (h-14, z-10), plus the drawer (scrim z-30, panel z-35). Open state is `mobileMenuOpen` on <body>. --}}
<header class="md:hidden sticky top-0 z-10 h-14 px-5 flex items-center justify-between bg-white/80 dark:bg-stone-900/80 backdrop-blur-lg border-b border-gray-200 dark:border-stone-800">
    <span class="text-xl font-medium tracking-tight">Re<span class="font-light text-red-600">Moment</span></span>
    <button type="button" @click="mobileMenuOpen = !mobileMenuOpen" aria-label="Menu" :aria-expanded="mobileMenuOpen"
            class="w-10 h-10 -mr-2 flex items-center justify-center text-gray-600 dark:text-gray-400">
        <i class="fa-solid fa-bars text-xl"></i>
    </button>
</header>

<div x-show="mobileMenuOpen" x-cloak
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @click="mobileMenuOpen = false"
     class="md:hidden fixed inset-0 bg-black/40 z-30"></div>

<div x-show="mobileMenuOpen" x-cloak
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 -translate-x-full"
     x-transition:enter-end="opacity-100 translate-x-0"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100 translate-x-0"
     x-transition:leave-end="opacity-0 -translate-x-full"
     class="md:hidden fixed top-0 left-0 h-full w-72 bg-white dark:bg-stone-900 z-[35] shadow-xl flex flex-col overflow-y-auto">
    <div class="p-8 border-b border-gray-200 dark:border-stone-800">
        <x-wordmark />
    </div>
    <x-nav-list />
</div>
