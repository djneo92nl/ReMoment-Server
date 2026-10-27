{{-- A TV's sources as tiles: outlined when active, the input as a badge, a dot for signal (docs/ux/sources.md, docs/architecture/beovision-tv.md). --}}
<div>
    <p class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-2">{{ $title }}</p>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
        @foreach($items as $source)
            @php
                $tile = 'relative flex flex-col items-center justify-center gap-1.5 px-3 py-4 rounded-2xl text-center text-sm bg-gray-100 dark:bg-stone-800 transition-colors '
                    .($source['active'] ? 'ring-2 ring-gray-900 dark:ring-gray-100 font-semibold text-gray-900 dark:text-gray-100' : 'text-gray-700 dark:text-gray-300');
            @endphp
            <button type="button" wire:key="tv-src-{{ $source['id'] }}"
                    @if($supportsActivation) wire:click="activateSource({{ $source['id'] }})" @else disabled @endif
                    class="{{ $tile }} {{ $supportsActivation ? 'hover:bg-gray-200 dark:hover:bg-stone-700' : '' }}">
                @if($source['signal'] !== null)
                    <span class="absolute top-2.5 right-2.5 w-2 h-2 rounded-full {{ $source['signal'] ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-stone-600' }}"
                          title="{{ $source['signal'] ? 'Signal' : 'No signal' }}"></span>
                @endif
                <i class="{{ \App\Domain\Device\SourceIcon::classes($source['type'], $source['name']) }} text-base text-gray-400 dark:text-gray-500"></i>
                <span class="block max-w-full truncate">{{ $source['name'] }}</span>
                @if($source['input'])
                    <span class="px-2 py-0.5 rounded-md bg-gray-200/70 dark:bg-stone-700/60 text-xs font-normal text-gray-500 dark:text-gray-400">{{ $source['input'] }}</span>
                @endif
            </button>
        @endforeach
    </div>
</div>
