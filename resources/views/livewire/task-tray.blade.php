<div @if($tasks) wire:poll.2s @else wire:poll.10s @endif data-test="task-tray">
    @if($tasks)
        <div class="pointer-events-auto fixed bottom-24 right-4 z-50 w-72 max-w-[calc(100vw-2rem)] space-y-2" role="status" aria-live="polite">
            @foreach($tasks as $task)
                <x-card class="p-3" wire:key="task-{{ $task['key'] }}">
                    <div class="flex items-center gap-2 text-sm">
                        <i class="fa-solid {{ $task['state'] === 'queued' ? 'fa-hourglass-half' : 'fa-circle-notch fa-spin' }} text-gray-500"></i>
                        <span class="min-w-0 flex-1 truncate" title="{{ $task['title'] }}">{{ $task['title'] }}</span>
                        @if($task['percent'] !== null)
                            <span class="text-xs tabular-nums text-gray-500">{{ $task['estimated'] ? '~' : '' }}{{ $task['percent'] }}%</span>
                        @endif
                    </div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                        @if($task['percent'] !== null)
                            <div class="h-full rounded-full bg-gray-500 transition-all" style="width: {{ $task['percent'] }}%"></div>
                        @elseif($task['state'] === 'running')
                            <div class="h-full w-1/3 animate-pulse rounded-full bg-gray-500"></div>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ $task['state'] === 'queued' ? 'Waiting for a worker' : ($task['detail'] ?? 'Working on it') }}</p>
                </x-card>
            @endforeach
        </div>
    @endif
</div>
