<div wire:init="load">
    @if($available && $loaded && !$failed && ($tracks === null || count($tracks) > 0 || $message))
        <div class="border-t border-gray-100 dark:border-stone-800 px-6 py-5">
            <div class="flex flex-wrap items-center justify-between gap-3 {{ is_array($tracks) && count($tracks) > 0 ? 'mb-3' : '' }}">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">
                    <i class="fa-brands fa-spotify text-emerald-500 mr-1.5"></i>More on Spotify
                </h2>
                @if(is_array($tracks) && count($tracks) > 0 && !$message)
                    <button wire:click="addAll" wire:loading.attr="disabled" type="button"
                            class="px-3 py-1 rounded-full bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-xs text-gray-600 dark:text-gray-400 transition-colors disabled:opacity-60">
                        <span wire:loading.remove wire:target="addAll">Add all {{ count($tracks) }} to album</span>
                        <span wire:loading wire:target="addAll">Adding…</span>
                    </button>
                @endif
            </div>

            @if($message)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
            @elseif($tracks === null)
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">This album isn't on Spotify.</p>
            @else
                <div class="divide-y divide-gray-50 dark:divide-stone-800/50">
                    @foreach($tracks as $track)
                        <div class="flex items-center gap-4 py-2">
                            <span class="w-5 text-center text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">{{ $track['number'] ?: '' }}</span>
                            <p class="flex-1 min-w-0 text-sm text-gray-700 dark:text-gray-300 truncate">{{ $track['name'] }}</p>
                            @if($track['duration'])
                                <span class="text-xs text-gray-400 dark:text-gray-600 flex-shrink-0">{{ gmdate('g:i', $track['duration']) }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
