<div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">

    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-stone-800">
        @if($renaming)
            <form wire:submit.prevent="rename" class="flex items-center gap-2 flex-1">
                <input type="text" wire:model="editName" autofocus
                       class="flex-1 text-sm rounded-lg border-gray-300 dark:border-stone-700 dark:bg-stone-800 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500">
                <button type="submit" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">Save</button>
                <button type="button" wire:click="$set('renaming', false)" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">Cancel</button>
            </form>
        @else
            <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Tracks</h2>
            <button wire:click="$set('renaming', true)" class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fa-solid fa-pen text-xs mr-1"></i>Rename
            </button>
        @endif
    </div>

    @if($tracks->isEmpty())
        <div class="flex flex-col items-center justify-center py-16 text-center px-8">
            <i class="fa-solid fa-music text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
            <p class="text-gray-500 dark:text-gray-500">No tracks in this playlist yet — search below to add some.</p>
        </div>
    @else
        <div class="divide-y divide-gray-50 dark:divide-stone-800/50">
            @foreach($tracks as $i => $track)
                <div class="flex items-center gap-3 px-6 py-3 hover:bg-gray-50 dark:hover:bg-stone-800/30 transition-colors group">
                    <div class="flex flex-col gap-0.5 flex-shrink-0">
                        <button wire:click="moveTrack({{ $track->id }}, -1)"
                                class="flex items-center justify-center w-5 h-4 rounded text-gray-300 dark:text-stone-600 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors {{ $i === 0 ? 'invisible' : '' }}">
                            <i class="fa-solid fa-chevron-up" style="font-size:.55rem"></i>
                        </button>
                        <button wire:click="moveTrack({{ $track->id }}, 1)"
                                class="flex items-center justify-center w-5 h-4 rounded text-gray-300 dark:text-stone-600 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors {{ $i === $tracks->count() - 1 ? 'invisible' : '' }}">
                            <i class="fa-solid fa-chevron-down" style="font-size:.55rem"></i>
                        </button>
                    </div>
                    <x-source-icon :source="$track->source" class="flex-shrink-0" />
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ $track->name }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 truncate">
                            {{ $track->artist?->name }}
                            @if($track->duration)
                                &middot; {{ gmdate('g:i', $track->duration) }}
                            @endif
                        </p>
                    </div>
                    <button wire:click="removeTrack({{ $track->id }})"
                            class="opacity-0 group-hover:opacity-100 transition-opacity w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-gray-100 dark:hover:bg-stone-700 flex-shrink-0"
                            title="Remove from playlist">
                        <i class="fa-solid fa-xmark text-xs"></i>
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    <div class="px-6 py-4 border-t border-gray-100 dark:border-stone-800">
        <div class="relative">
            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-gray-300 dark:text-stone-600"></i>
            <input type="text" wire:model.live.debounce.300ms="trackSearch" placeholder="Search tracks to add&hellip;"
                   class="w-full pl-9 pr-3 py-2 text-sm rounded-xl border-gray-200 dark:border-stone-700 dark:bg-stone-800 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500">
        </div>

        @if($searchResults->isNotEmpty())
            <div class="mt-2 space-y-0.5 max-h-64 overflow-y-auto">
                @foreach($searchResults as $result)
                    <button wire:click="addTrack({{ $result->id }})"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-left text-sm hover:bg-gray-50 dark:hover:bg-stone-800 transition-colors">
                        <x-source-icon :source="$result->source" class="flex-shrink-0" />
                        <div class="flex-1 min-w-0">
                            <p class="text-gray-800 dark:text-gray-200 truncate">{{ $result->name }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-600 truncate">{{ $result->artist?->name }}</p>
                        </div>
                        <i class="fa-solid fa-plus text-xs text-gray-400 dark:text-gray-600 flex-shrink-0"></i>
                    </button>
                @endforeach
            </div>
        @elseif(mb_strlen(trim($trackSearch)) >= 2)
            <p class="mt-2 text-xs text-gray-400 dark:text-gray-600 px-3">No matching tracks found.</p>
        @endif
    </div>
</div>
