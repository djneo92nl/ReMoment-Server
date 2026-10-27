{{-- One source in a picker: outlined when active, input and "Borrowed" as badges (docs/ux/sources.md). --}}
@php
    $badge = 'flex-shrink-0 px-2 py-0.5 rounded-md bg-gray-200/70 dark:bg-stone-700/60 text-xs text-gray-500 dark:text-gray-400';
    $rowClass = 'flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-left text-sm bg-gray-100 dark:bg-stone-800 transition-colors '
        .($source['active'] ? 'ring-2 ring-gray-900 dark:ring-gray-100 font-semibold text-gray-900 dark:text-gray-100' : 'text-gray-700 dark:text-gray-300');
@endphp
@if($supportsActivation)
    <button type="button" wire:click="activateSource({{ $source['id'] }})" @isset($closeOnPick) @click="$dispatch('close')" @endisset
            class="{{ $rowClass }} hover:bg-gray-200 dark:hover:bg-stone-700">
@else
    <div class="{{ $rowClass }}">
@endif
    <i class="{{ \App\Domain\Device\SourceIcon::classes($source['type'], $source['name']) }} text-xs w-4 text-center text-gray-400 dark:text-gray-500 flex-shrink-0"></i>
    <span class="flex-1 min-w-0">
        <span class="block truncate">{{ $source['name'] }}</span>
        @if($source['borrowed'] && $source['provider_name'])
            <span class="block truncate text-xs font-normal text-gray-400 dark:text-gray-600">{{ $source['provider_name'] }}</span>
        @endif
    </span>
    @if($source['input'])<span class="{{ $badge }}">{{ $source['input'] }}</span>@endif
    @if($source['borrowed'])<span class="{{ $badge }}">Borrowed</span>@endif
@if($supportsActivation)
    </button>
@else
    </div>
@endif
