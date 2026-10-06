<div wire:init="load">
    @if($available && $loaded && !$failed && ($tracks === null || count($tracks) > 0 || $message))
        <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 border-t border-gray-100 dark:border-stone-800 text-sm">
            <div class="min-w-0 text-gray-500 dark:text-gray-500">
                <i class="fa-brands fa-spotify text-emerald-500 mr-1.5"></i>
                @if($message)
                    {{ $message }}
                @elseif($tracks === null)
                    This album isn't on Spotify.
                @else
                    {{ count($tracks) }} more {{ Str::plural('track', count($tracks)) }} on Spotify:
                    <span class="text-gray-400 dark:text-gray-600">{{ collect($tracks)->pluck('name')->take(3)->implode(', ') }}{{ count($tracks) > 3 ? '…' : '' }}</span>
                @endif
            </div>
            @if(is_array($tracks) && count($tracks) > 0 && !$message)
                <button wire:click="addAll" wire:loading.attr="disabled" type="button"
                        class="px-3 py-1 rounded-full bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-xs text-gray-600 dark:text-gray-400 transition-colors disabled:opacity-60">
                    <span wire:loading.remove wire:target="addAll">Add to album</span>
                    <span wire:loading wire:target="addAll">Adding…</span>
                </button>
            @endif
        </div>
    @endif
</div>
