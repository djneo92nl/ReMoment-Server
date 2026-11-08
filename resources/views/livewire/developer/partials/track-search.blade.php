<div>
    <label class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Track</label>
    <x-text-input wire:model.live.debounce.300ms="search" placeholder="Search your own files" class="mt-2 w-full" data-test="track-search" />
    @if($tracks->isNotEmpty())
        <ul class="mt-2 divide-y divide-gray-100 dark:divide-stone-800 rounded-xl border border-gray-200 dark:border-stone-700">
            @foreach($tracks as $t)
                <li><button type="button" wire:click="choose({{ $t->id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-stone-800">
                    <span class="text-gray-900 dark:text-gray-100">{{ $t->name }}</span>
                    <span class="text-gray-500"> · {{ $t->artist?->name }}</span>
                </button></li>
            @endforeach
        </ul>
    @endif
    @if($track)<p class="mt-2 text-sm text-gray-500">Chosen: <span class="text-gray-900 dark:text-gray-100">{{ $track->name }}</span> · {{ $track->artist?->name }}</p>@endif
</div>
