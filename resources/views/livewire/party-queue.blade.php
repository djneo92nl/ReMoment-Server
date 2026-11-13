<div class="space-y-5" wire:poll.10s>
    @if(!$device)
        <x-empty-state icon="champagne-glasses" title="The party is over" text="This link no longer works." />
    @else
        @if($notice)
            <div role="status" class="rounded-2xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-300">{{ $notice }}</div>
        @endif
        @if($problem)
            <div role="alert" class="rounded-2xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">{{ $problem }}</div>
        @endif

        <div>
            <x-text-input type="search" wire:model.live.debounce.300ms="search" placeholder="Find a track or an artist" aria-label="Find a track" class="w-full" data-test="search" />
        </div>

        @if($results)
            <x-card flush>
                <ul class="divide-y divide-gray-100 dark:divide-stone-800/70" data-test="results">
                    @foreach($results as $track)
                        <li class="flex items-center gap-3 px-4 py-2.5" wire:key="result-{{ $track['id'] }}">
                            <x-artwork-thumb :src="$track['image']" :seed="$track['name']" size="w-10 h-10" :proxy="120" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $track['name'] }}</p>
                                <p class="truncate text-xs text-gray-400 dark:text-gray-600">{{ $track['artist'] }}</p>
                            </div>
                            <x-secondary-button type="button" size="sm" wire:click="add({{ $track['id'] }})" wire:loading.attr="disabled" wire:target="add">Add</x-secondary-button>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @elseif(mb_strlen(trim($search)) >= 2)
            <p class="text-sm text-gray-500">Nothing found that can be played here.</p>
        @endif

        <section>
            <h2 class="mb-2 text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Now playing</h2>
            <x-card class="px-4 py-3">
                @if($playing)
                    <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $playing['name'] }}</p>
                    <p class="truncate text-xs text-gray-400 dark:text-gray-600">{{ $playing['artist'] }}</p>
                @else
                    <p class="text-sm text-gray-500">Nothing yet. Add the first track.</p>
                @endif
            </x-card>
        </section>

        @if($upNext)
            <section>
                <h2 class="mb-2 text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Up next</h2>
                <x-card flush>
                    <ol class="divide-y divide-gray-100 dark:divide-stone-800/70" data-test="up-next">
                        @foreach($upNext as $i => $item)
                            <li class="flex items-center gap-3 px-4 py-2.5" wire:key="next-{{ $i }}">
                                <x-artwork-thumb :src="$item['image']" :seed="$item['name']" size="w-10 h-10" :proxy="120" />
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-gray-900 dark:text-gray-100">{{ $item['name'] }}</p>
                                    <p class="truncate text-xs text-gray-400 dark:text-gray-600">{{ $item['artist'] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </x-card>
            </section>
        @endif
    @endif
</div>
