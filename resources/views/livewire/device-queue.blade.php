@php($canRemove = in_array('queue_remove', $capabilities, true))
@php($canMove = in_array('queue_move', $capabilities, true))
@php($canClear = in_array('queue_clear', $capabilities, true) && !empty($items))
<x-card x-data="liveDevice({{ $device->id }}, 10000, 60000)" wire:init="load" title="Up Next" class="p-8">

    @if($canClear)
        <div class="flex justify-end mb-2">
            <button type="button" wire:click="clearQueue" wire:confirm="Clear everything after the current track?" class="text-xs text-gray-500 hover:text-gray-800 dark:hover:text-gray-200">Clear queue</button>
        </div>
    @endif
    @if($queueError)
        <p class="text-sm text-red-500 mb-2">{{ $queueError }}</p>
    @endif

    @if(!$ready)
        <div class="space-y-3">
            @for($i = 0; $i < 3; $i++)
                <div class="h-10 bg-gray-100 dark:bg-stone-800 rounded-xl animate-pulse"></div>
            @endfor
        </div>
    @elseif($error)
        <p class="text-sm text-gray-500">{{ $error }}</p>
    @elseif(empty($items))
        <p class="text-sm text-gray-500">Nothing queued after the current track.</p>
    @else
        <ol class="space-y-1" @if($canMove) x-data="queueSort" @dragstart="start($event)" @dragover="over($event)" @drop="drop($event)" @dragend="end()" @endif>
            @foreach($items as $i => $item)
                <li wire:key="q-{{ $i }}-{{ $item['uri'] ?? $item['name'] }}" @if($canMove) draggable="true" data-pos="{{ $i + 1 }}" data-uri="{{ $item['uri'] }}" @endif class="flex items-center gap-3 px-2 py-2 rounded-xl hover:bg-gray-50 dark:hover:bg-stone-800/50 transition-colors">
                    <span class="w-5 text-xs text-right text-gray-400 dark:text-gray-600 flex-shrink-0">{{ $i + 1 }}</span>
                    @if($item['image'])
                        <img src="{{ $item['image'] }}" alt="" loading="lazy" class="w-9 h-9 rounded-xl object-cover flex-shrink-0">
                    @else
                        <div class="w-9 h-9 rounded-xl bg-gray-100 dark:bg-stone-800 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-music text-xs text-gray-300 dark:text-stone-600"></i>
                        </div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ $item['name'] }}</div>
                        @if($item['artist'] || $item['album'])
                            <div class="text-xs text-gray-500 truncate">
                                {{ collect([$item['artist'], $item['album']])->filter()->implode(' · ') }}
                            </div>
                        @endif
                    </div>
                    @if($item['duration'])
                        <span class="text-xs text-gray-400 dark:text-gray-600 flex-shrink-0">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($item['duration']) }}</span>
                    @endif
                    @php($uri = \Illuminate\Support\Js::from($item['uri']))
                    @if($canMove && $i > 0)
                        <button type="button" wire:click="moveInQueue({{ $i + 1 }}, {{ $i }}, {{ $uri }})" title="Move up" class="w-7 h-7 flex-shrink-0 rounded-full text-xs text-gray-400 hover:text-gray-800 dark:hover:text-gray-200"><i class="fa-solid fa-chevron-up"></i></button>
                    @endif
                    @if($canMove && $i < count($items) - 1)
                        <button type="button" wire:click="moveInQueue({{ $i + 1 }}, {{ $i + 2 }}, {{ $uri }})" title="Move down" class="w-7 h-7 flex-shrink-0 rounded-full text-xs text-gray-400 hover:text-gray-800 dark:hover:text-gray-200"><i class="fa-solid fa-chevron-down"></i></button>
                    @endif
                    @if($canRemove)
                        <button type="button" wire:click="removeFromQueue({{ $i + 1 }}, {{ $uri }})" title="Remove from the queue" class="w-7 h-7 flex-shrink-0 rounded-full text-xs text-gray-400 hover:text-red-500"><i class="fa-solid fa-xmark"></i></button>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</x-card>
