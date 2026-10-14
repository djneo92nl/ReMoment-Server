<aside class="hidden md:flex flex-col w-72 bg-white dark:bg-stone-900 border-r border-gray-200 dark:border-stone-800 shadow-sm flex-shrink-0">
    <div class="p-8 border-b border-gray-200 dark:border-stone-800">
        <x-wordmark />
        <p class="mt-1 text-xs text-gray-400 tracking-wide">Universal Device Controller</p>
    </div>

    <x-nav-list />

    <div class="p-6 border-t border-gray-200 dark:border-stone-800 text-xs text-gray-400 space-y-1">
        <div>{{ config('app.name', 'ReMoment') }}</div>
        <div class="text-gray-300 dark:text-stone-600">{{ now()->format('M j, Y') }}</div>
    </div>
</aside>
