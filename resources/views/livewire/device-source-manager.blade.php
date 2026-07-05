@php
    $typeIcon = fn($type) => match(strtoupper($type ?? '')) {
        'HDMI', 'TV'         => 'fa-display',
        'TUNEIN', 'RADIO'    => 'fa-tower-broadcast',
        'LINEIN'             => 'fa-plug',
        'DLNA', 'UPNP'       => 'fa-network-wired',
        'CD', 'DVD'          => 'fa-compact-disc',
        'OPTICAL'            => 'fa-circle',
        'BLUETOOTH'          => 'fa-bluetooth',
        default              => 'fa-plug',
    };
    $allSources     = collect($sources);
    $ownSources     = $allSources->where('borrowed', false)->values();
    $borrowedSources = $allSources->where('borrowed', true)->values();
    $visible        = $allSources->where('hidden', false);        // own + borrowed that are un-hidden
    $hiddenCount    = $allSources->where('hidden', true)->count();
    $modalName      = 'srcs-mgr-' . $device->id;
@endphp

<div>
{{-- ── Inline card ── --}}
<div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">

    <div class="flex items-center justify-between mb-5">
        <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">Sources</h2>
        <button x-data @click.stop="$dispatch('open-modal', '{{ $modalName }}')"
                class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors">
            <i class="fa-solid fa-sliders text-xs"></i>
            Manage
        </button>
    </div>

    @php $chipClass = 'flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-gray-100 dark:bg-stone-800 text-sm text-gray-700 dark:text-gray-300 transition-colors'; @endphp

    @if($visible->isEmpty())
        <p class="text-sm text-gray-400 dark:text-gray-600">
            No visible sources.
            <button x-data @click.stop="$dispatch('open-modal', '{{ $modalName }}')"
                    class="underline hover:text-gray-600 dark:hover:text-gray-400 transition-colors">Manage</button>
            to configure.
        </p>
    @else
        <div class="flex flex-wrap gap-2">
            @foreach($visible as $source)
                @if($supportsActivation)
                    <button wire:click="activateSource({{ $source['id'] }})"
                            class="{{ $chipClass }} hover:bg-gray-200 dark:hover:bg-stone-700 hover:text-gray-900 dark:hover:text-gray-100 {{ $source['borrowed'] ? 'opacity-70' : '' }}">
                        <i class="fa-solid {{ $typeIcon($source['type']) }} text-xs text-gray-400 dark:text-gray-500"></i>
                        {{ $source['name'] }}
                    </button>
                @else
                    <span class="{{ $chipClass }} {{ $source['borrowed'] ? 'opacity-70' : '' }}">
                        <i class="fa-solid {{ $typeIcon($source['type']) }} text-xs text-gray-400 dark:text-gray-500"></i>
                        {{ $source['name'] }}
                    </span>
                @endif
            @endforeach
        </div>
        @if($hiddenCount > 0)
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-600">{{ $hiddenCount }} hidden</p>
        @endif
    @endif
</div>

{{-- ── Management modal ── --}}
<x-modal name="{{ $modalName }}" maxWidth="md">
    <div>
        {{-- Modal header --}}
        <div class="flex items-center justify-between px-6 pt-6 pb-4 border-b border-gray-100 dark:border-stone-800">
            <div class="flex items-center gap-2.5">
                <i class="fa-solid fa-input-pipe text-gray-400 dark:text-gray-500"></i>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Sources</h3>
                <span class="text-xs text-gray-400 dark:text-gray-600">{{ $visible->count() }} visible</span>
                @if($borrowedSources->count() > 0)
                    <span class="text-xs text-gray-400 dark:text-gray-600">· {{ $borrowedSources->count() }} borrowed</span>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <button wire:click="$toggle('editMode')"
                        class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium transition-colors {{ $editMode ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' : 'text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-stone-800' }}">
                    <i class="fa-solid fa-sliders text-xs"></i>
                    <span>{{ $editMode ? 'Done' : 'Edit' }}</span>
                </button>
                <button @click="$dispatch('close')"
                        class="flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                    <i class="fa-solid fa-xmark text-xs"></i>
                </button>
            </div>
        </div>

        @if(empty($sources))
            <div class="px-6 py-8 text-center">
                <p class="text-sm text-gray-400 dark:text-gray-600 mb-1">No sources synced yet.</p>
                <p class="text-xs text-gray-400 dark:text-gray-600 font-mono">php artisan devices:sync-sources</p>
            </div>
        @else
            <ul class="px-3 py-3 space-y-0.5 max-h-[60vh] overflow-y-auto">

                @if($editMode)
                    {{-- Own sources: reorder + eye toggle --}}
                    @foreach($ownSources as $i => $source)
                        <li class="flex items-center gap-2 px-3 py-2 rounded-xl {{ $source['hidden'] ? 'opacity-40' : '' }}">
                            <div class="flex flex-col gap-0.5 flex-shrink-0">
                                <button wire:click="moveUp({{ $source['id'] }})"
                                        class="flex items-center justify-center w-5 h-4 rounded text-gray-300 dark:text-stone-600 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors {{ $i === 0 ? 'invisible' : '' }}">
                                    <i class="fa-solid fa-chevron-up" style="font-size:.55rem"></i>
                                </button>
                                <button wire:click="moveDown({{ $source['id'] }})"
                                        class="flex items-center justify-center w-5 h-4 rounded text-gray-300 dark:text-stone-600 hover:text-gray-500 dark:hover:text-gray-400 hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors {{ $i === $ownSources->count() - 1 ? 'invisible' : '' }}">
                                    <i class="fa-solid fa-chevron-down" style="font-size:.55rem"></i>
                                </button>
                            </div>
                            <i class="fa-solid {{ $typeIcon($source['type']) }} text-gray-400 dark:text-gray-500 w-4 text-center flex-shrink-0 text-xs"></i>
                            <span class="flex-1 text-sm text-gray-800 dark:text-gray-200 truncate">{{ $source['name'] }}</span>
                            <span class="text-xs text-gray-400 dark:text-gray-600 flex-shrink-0">{{ $source['type'] }}</span>
                            <button wire:click="toggleHidden({{ $source['id'] }})"
                                    title="{{ $source['hidden'] ? 'Show' : 'Hide' }}"
                                    class="flex items-center justify-center w-7 h-7 rounded-lg hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors flex-shrink-0 {{ $source['hidden'] ? 'text-gray-300 dark:text-stone-600' : 'text-gray-500 dark:text-gray-400' }}">
                                <i class="fa-solid {{ $source['hidden'] ? 'fa-eye-slash' : 'fa-eye' }} text-xs"></i>
                            </button>
                        </li>
                    @endforeach

                    {{-- Borrowed sources: eye toggle only, no reorder --}}
                    @if($borrowedSources->count() > 0)
                        <li class="px-3 pt-3 pb-1 border-t border-gray-100 dark:border-stone-800 mt-1">
                            <span class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Borrowed</span>
                        </li>
                        @foreach($borrowedSources as $source)
                            <li class="flex items-center gap-2 px-3 py-2 rounded-xl {{ $source['hidden'] ? 'opacity-40' : '' }}">
                                <div class="w-5 flex-shrink-0"></div>{{-- spacer aligns with own sources --}}
                                <i class="fa-solid {{ $typeIcon($source['type']) }} text-gray-400 dark:text-gray-500 w-4 text-center flex-shrink-0 text-xs"></i>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm text-gray-800 dark:text-gray-200 truncate block">{{ $source['name'] }}</span>
                                    <span class="text-xs text-gray-400 dark:text-gray-600">↳ {{ $source['provider_name'] }}</span>
                                </div>
                                <span class="text-xs text-gray-400 dark:text-gray-600 flex-shrink-0">{{ $source['type'] }}</span>
                                <button wire:click="toggleHidden({{ $source['id'] }})"
                                        title="{{ $source['hidden'] ? 'Show' : 'Hide' }}"
                                        class="flex items-center justify-center w-7 h-7 rounded-lg hover:bg-gray-100 dark:hover:bg-stone-800 transition-colors flex-shrink-0 {{ $source['hidden'] ? 'text-gray-300 dark:text-stone-600' : 'text-gray-500 dark:text-gray-400' }}">
                                    <i class="fa-solid {{ $source['hidden'] ? 'fa-eye-slash' : 'fa-eye' }} text-xs"></i>
                                </button>
                            </li>
                        @endforeach
                    @endif

                @else
                    {{-- Normal mode: all visible (own + borrowed) with activate --}}
                    @foreach($visible as $source)
                        <li class="flex items-center gap-3 px-3 py-2 rounded-xl text-sm group hover:bg-gray-50 dark:hover:bg-stone-800/60 transition-colors {{ $source['borrowed'] ? 'opacity-70' : '' }}">
                            <i class="fa-solid {{ $typeIcon($source['type']) }} text-gray-400 dark:text-gray-500 w-4 text-center flex-shrink-0 text-xs"></i>
                            <div class="flex-1 min-w-0">
                                <span class="text-gray-800 dark:text-gray-200 truncate block">{{ $source['name'] }}</span>
                                @if($source['borrowed'])
                                    <span class="text-xs text-gray-400 dark:text-gray-600">↳ {{ $source['provider_name'] }}</span>
                                @endif
                            </div>
                            <span class="text-xs text-gray-400 dark:text-gray-600 flex-shrink-0">{{ $source['type'] }}</span>
                            @if($supportsActivation)
                                <button wire:click="activateSource({{ $source['id'] }})" @click="$dispatch('close')"
                                        class="flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 dark:text-gray-600 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-700 dark:hover:text-gray-300 transition-colors opacity-0 group-hover:opacity-100">
                                    <i class="fa-solid fa-play text-xs"></i>
                                </button>
                            @endif
                        </li>
                    @endforeach

                    @if($hiddenCount > 0)
                        <li class="px-3 pt-3 pb-1 border-t border-gray-100 dark:border-stone-800 mt-1">
                            <span class="text-xs text-gray-400 dark:text-gray-600">{{ $hiddenCount }} hidden · use Edit to show them</span>
                        </li>
                    @endif
                @endif

            </ul>
        @endif
    </div>
</x-modal>
</div>
