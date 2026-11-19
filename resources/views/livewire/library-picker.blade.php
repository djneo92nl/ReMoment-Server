<div class="picker-grid overflow-hidden rounded-3xl border border-gray-200/70 bg-white shadow-sm dark:border-stone-800 dark:bg-stone-900" data-test="library-picker">
    {{-- Left: browse --}}
    <div class="flex min-h-0 min-w-0 flex-col">
        <div class="space-y-2 border-b border-gray-100 p-3 dark:border-stone-800">
            <div class="flex flex-wrap gap-1.5">
                @foreach($types as $t)
                    <button type="button" wire:click="setTab('{{ $t }}')" data-test="picker-tab-{{ $t }}"
                            class="rounded-full border px-3 py-1 text-xs {{ $tab === $t ? 'border-gray-300 bg-gray-100 font-medium text-gray-900 dark:border-stone-600 dark:bg-stone-800 dark:text-gray-100' : 'border-gray-200 text-gray-500 dark:border-stone-700' }}">
                        {{ ['artist' => 'Artists', 'album' => 'Albums', 'track' => 'Tracks', 'playlist' => 'Playlists'][$t] }}
                    </button>
                @endforeach
            </div>
            <x-text-input type="search" wire:model.live.debounce.300ms="search" placeholder="Search {{ $tab }}s" class="w-full" data-test="picker-search" />
        </div>

        <div class="picker-scroll flex-1 overflow-y-auto" data-test="picker-list">
            @forelse($rows as $row)
                @php($in = isset($pickedKeys[$row['key']]))
                <div wire:key="row-{{ $row['key'] }}-{{ $row['depth'] }}" wire:click="focusItem('{{ $row['type'] }}', {{ $row['id'] }})"
                     class="tint-hero flex cursor-pointer items-center gap-3 border-b border-gray-100 py-2 pr-3 text-sm dark:border-stone-800 {{ $focus && $focus['type'] === $row['type'] && $focus['id'] === $row['id'] ? 'ring-1 ring-inset ring-gray-400/50' : '' }}"
                     style="padding-left: {{ 0.75 + $row['depth'] * 1.5 }}rem; {{ $row['tint'] }}" data-test="picker-row" data-key="{{ $row['key'] }}">
                    <span class="w-3 flex-shrink-0 text-xs text-gray-400">
                        @if($row['expandable'])<i class="fa-solid fa-chevron-{{ $row['open'] ? 'down' : 'right' }}"></i>@endif
                    </span>
                    <x-artwork-thumb :src="$row['art']" :seed="$row['name']" size="w-9 h-9" :rounded="$row['type'] === 'artist' ? 'rounded-full' : 'rounded'" :icon="['artist' => 'fa-solid fa-user', 'album' => 'fa-solid fa-compact-disc', 'track' => 'fa-solid fa-music', 'playlist' => 'fa-solid fa-list'][$row['type']]" proxy="120" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</p>
                        @if($row['sub'] !== '')<p class="truncate text-xs text-gray-500">{{ $row['sub'] }}</p>@endif
                    </div>
                    <button type="button" wire:click.stop="toggle('{{ $row['type'] }}', {{ $row['id'] }})" data-test="picker-add"
                            aria-label="{{ $in ? 'Remove' : 'Add' }} {{ $row['name'] }}"
                            class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full border text-xs {{ $in ? 'border-transparent bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' : 'border-gray-300 text-gray-500 dark:border-stone-600' }}">
                        <i class="fa-solid fa-{{ $in ? 'check' : 'plus' }}"></i>
                    </button>
                </div>
            @empty
                <p class="p-6 text-center text-sm text-gray-500" data-test="picker-empty">Nothing found.</p>
            @endforelse
            @if($hasMore)
                <div class="p-3 text-center"><x-secondary-button size="sm" wire:click="more" data-test="picker-more">Show more</x-secondary-button></div>
            @endif
        </div>
    </div>

    {{-- Right: details of the focused item, then what is picked --}}
    <div class="flex min-h-0 min-w-0 flex-col">
        <div class="tint-hero border-b border-gray-100 p-4 dark:border-stone-800" style="{{ $detail['tint'] ?? '' }}" data-test="picker-detail">
            @if($detail)
                <div class="flex items-center gap-4">
                    <x-artwork-thumb :src="$detail['art']" :seed="$detail['name']" size="w-20 h-20" :rounded="$detail['type'] === 'artist' ? 'rounded-full' : 'rounded-lg'" :icon="['artist' => 'fa-solid fa-user', 'album' => 'fa-solid fa-compact-disc', 'track' => 'fa-solid fa-music', 'playlist' => 'fa-solid fa-list'][$detail['type']]" proxy="320" />
                    <div class="min-w-0 flex-1">
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-stone-800 dark:text-gray-400">{{ ucfirst($detail['type']) }}</span>
                        <p class="mt-1 truncate text-base font-medium text-gray-900 dark:text-gray-100">{{ $detail['name'] }}</p>
                        <p class="truncate text-sm text-gray-500">{{ $detail['sub'] }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $detail['tracks'] }} {{ Str::plural('track', $detail['tracks']) }} · about {{ $detail['megabytes'] }} MB</p>
                    </div>
                </div>
                <x-secondary-button size="sm" class="mt-3" wire:click="toggle('{{ $detail['type'] }}', {{ $detail['id'] }})" data-test="picker-detail-toggle">
                    {{ $detail['picked'] ? 'Remove from selection' : 'Add to selection' }}
                </x-secondary-button>
            @else
                <p class="py-5 text-center text-sm text-gray-400"><i class="fa-solid fa-hand-pointer mr-1"></i>Click an item to see its details</p>
            @endif
        </div>

        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2 dark:border-stone-800">
            <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">Selected{{ count($picked) ? ' ('.count($picked).')' : '' }}</h3>
            @if(count($picked))<button type="button" wire:click="clear" class="text-xs text-gray-500 underline" data-test="picker-clear">Clear</button>@endif
        </div>
        <div class="picker-basket flex-1 overflow-y-auto" data-test="picker-picked">
            @forelse($picked as $p)
                <div wire:key="picked-{{ $p['key'] }}" style="{{ $p['tint'] }}" class="tint-hero flex items-center gap-3 border-b border-gray-100 px-4 py-2 text-sm dark:border-stone-800" data-test="picker-picked-row">
                    <x-artwork-thumb :src="$p['art']" :seed="$p['name']" size="w-9 h-9" :rounded="$p['type'] === 'artist' ? 'rounded-full' : 'rounded'" :icon="['artist' => 'fa-solid fa-user', 'album' => 'fa-solid fa-compact-disc', 'track' => 'fa-solid fa-music', 'playlist' => 'fa-solid fa-list'][$p['type']]" proxy="120" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-gray-900 dark:text-gray-100">{{ $p['name'] }}</p>
                        <p class="truncate text-xs text-gray-500">{{ ucfirst($p['type']) }} · {{ $p['tracks'] }} {{ Str::plural('track', $p['tracks']) }}</p>
                    </div>
                    <button type="button" wire:click="remove('{{ $p['type'] }}', {{ $p['id'] }})" aria-label="Remove {{ $p['name'] }}" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
                </div>
            @empty
                <p class="p-6 text-center text-sm text-gray-400" data-test="picker-none">Nothing selected yet.</p>
            @endforelse
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-stone-800">
            <span data-test="picker-total">{{ $total }} {{ Str::plural('track', $total) }} · about {{ $megabytes }} MB</span>
            <x-primary-button size="md" wire:click="confirm" :disabled="count($picked) === 0" data-test="picker-confirm">{{ $confirmLabel }}</x-primary-button>
        </div>
    </div>
</div>
