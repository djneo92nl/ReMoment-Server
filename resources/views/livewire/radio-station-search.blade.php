<div class="space-y-4">
    <form wire:submit="search" class="flex items-center gap-3">
        <div class="relative flex-1">
            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-gray-300 dark:text-stone-600"></i>
            <x-text-input type="text" wire:model="query" placeholder="Search stations on radio-browser.info, e.g. Radio Fip" class="w-full pl-10 py-3" />
        </div>
        <x-secondary-button type="submit">
            <span wire:loading.remove wire:target="search"><i class="fa-solid fa-magnifying-glass"></i> Search</span>
            <span wire:loading wire:target="search"><i class="fa-solid fa-spinner fa-spin"></i> Searching</span>
        </x-secondary-button>
    </form>

    @if($searched !== null)
        @if(empty($results))
            <p class="text-sm text-gray-400 dark:text-gray-600">No playable stations found for “{{ $searched }}”.</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-stone-800 rounded-xl border border-gray-100 dark:border-stone-800">
                @foreach($results as $i => $result)
                    <li class="flex items-center gap-4 px-4 py-3" wire:key="radio-result-{{ $i }}">
                        <div class="w-10 h-10 rounded-lg overflow-hidden flex-shrink-0 bg-gray-100 dark:bg-stone-800 flex items-center justify-center">
                            <i class="fa-solid fa-radio text-gray-300 dark:text-stone-600"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $result['name'] }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-600 truncate">
                                {{ collect([$result['genre'], $result['country'], $result['codec'], $result['bitrate'] ? $result['bitrate'].' kbps' : null])->filter()->implode(' · ') }}
                            </p>
                        </div>
                        @if(isset($added[$result['url']]))
                            <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400"><i class="fa-solid fa-check"></i> Added</span>
                        @else
                            <x-secondary-button type="button" wire:click="add({{ $i }})">
                                <i class="fa-solid fa-plus"></i> Add
                            </x-secondary-button>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="text-xs text-gray-400 dark:text-gray-600">
                Added stations play on every device that can play a stream. A device with its own radio (B&amp;O, Sonos) uses that instead once the station has its identifier.
            </p>
        @endif
    @endif
</div>
