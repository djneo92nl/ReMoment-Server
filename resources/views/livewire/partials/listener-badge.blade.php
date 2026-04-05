<a href="{{ route('settings.listeners') }}"
   title="{{ $listenerRunning ? 'Listener active' : 'No listener running' }}"
   class="flex items-center gap-1.5 text-xs transition-colors {{ $listenerRunning ? 'text-emerald-600 dark:text-emerald-500' : 'text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400' }}">
    @if($listenerRunning)
        <span class="relative flex w-2 h-2">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full w-2 h-2 bg-emerald-500"></span>
        </span>
        <span class="hidden sm:inline">Listener</span>
    @else
        <span class="w-2 h-2 rounded-full bg-gray-300 dark:bg-stone-700"></span>
        <span class="hidden sm:inline">No listener</span>
    @endif
</a>
