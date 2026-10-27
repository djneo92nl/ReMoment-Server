<div wire:init="load" wire:poll.10s>
@if($visible)
    <x-card class="p-8" title="Jellyfin">
        @if($error)
            <p class="mb-4 text-xs text-red-500">{{ $error }}</p>
        @endif

        @if(!$ready)
            <div class="h-24 bg-gray-100 dark:bg-stone-800 rounded-xl animate-pulse"></div>
        @elseif($lists === null)
            <p class="text-sm text-gray-500">Jellyfin did not answer: check the server address and key in Settings › Services.</p>
        @elseif(empty($lists['continue_watching']) && empty($lists['next_up']))
            <p class="text-sm text-gray-500">Nothing to continue or watch next.</p>
        @else
            <div class="space-y-6">
                @foreach(['continue_watching' => 'Continue watching', 'next_up' => 'Next up'] as $key => $heading)
                    @if(!empty($lists[$key]))
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">{{ $heading }}</p>
                            <div class="no-scrollbar flex gap-4 overflow-x-auto pb-1">
                                @foreach($lists[$key] as $tile)
                                    <button type="button" wire:key="jf-{{ $key }}-{{ $tile['id'] }}" wire:click="play('{{ $tile['id'] }}', {{ $tile['ticks'] }})"
                                            wire:loading.attr="disabled" wire:target="play"
                                            class="w-36 shrink-0 text-left rounded-2xl focus-visible:ring-2 focus-visible:ring-gray-900 dark:focus-visible:ring-gray-100 disabled:opacity-50">
                                        <x-artwork-thumb :src="$tile['image']" :seed="$tile['title']" icon="fa-solid fa-clapperboard" size="w-36 h-36" rounded="rounded-2xl" />
                                        @if($tile['progress'] > 0)
                                            <div class="mt-2 h-1 rounded-full bg-gray-200 dark:bg-stone-700"><div class="h-1 rounded-full bg-gray-900 dark:bg-gray-100" style="width: {{ $tile['progress'] }}%"></div></div>
                                        @endif
                                        <div class="mt-2 text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ $tile['title'] }}</div>
                                        @if($tile['subtitle'])
                                            <div class="text-xs text-gray-500 truncate">{{ $tile['subtitle'] }}</div>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </x-card>
@endif
</div>
