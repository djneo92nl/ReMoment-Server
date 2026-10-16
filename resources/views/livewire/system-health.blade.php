@php
    $badge = fn (bool $ok, string $okText = 'OK', string $badText = 'Down') => $ok
        ? '<span class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-600 dark:text-emerald-500"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>'.e($okText).'</span>'
        : '<span class="inline-flex items-center gap-1.5 text-sm font-medium text-red-600 dark:text-red-500"><span class="w-2 h-2 rounded-full bg-red-500"></span>'.e($badText).'</span>';
    $stateColors = [
        'playing' => 'bg-emerald-500', 'paused' => 'bg-amber-500',
        'standby' => 'bg-red-500', 'unreachable' => 'bg-gray-400',
    ];
@endphp

<div wire:poll.5s class="space-y-6">

    @if(session('health-success'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 6000)" x-show="show" x-transition
             style="position: fixed; right: 1.5rem; bottom: 1.5rem; z-index: 60; max-width: 24rem;"
             class="px-5 py-3 text-sm rounded-2xl shadow-xl border text-emerald-800 dark:text-emerald-200 bg-emerald-50 dark:bg-emerald-950 border-emerald-200 dark:border-emerald-800"
             data-test="health-toast">
            <i class="fa-solid fa-circle-check mr-2"></i>{{ session('health-success') }}
        </div>
    @endif

    {{-- Background processes --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-card class="p-6">
            <div class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">Supervisor</div>
            @if($supervisor === null)
                <span class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500"><span class="w-2 h-2 rounded-full bg-gray-400"></span>Unknown</span>
                <div class="mt-2 text-xs text-gray-500">Can't be checked on this system</div>
            @else
                {!! $badge($supervisor, 'Running', 'Not running') !!}
                <div class="mt-2 text-xs text-gray-500">Keeps the queue, scheduler and listeners alive</div>
            @endif
        </x-card>

        <x-card class="p-6">
            <div class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">MQTT broker</div>
            {!! $badge($broker['ok'], 'Reachable', 'Unreachable') !!}
            <div class="mt-2 text-xs font-mono text-gray-500">{{ $broker['address'] }}</div>
            <div class="text-xs font-mono text-gray-400 dark:text-gray-600">{{ $broker['ws_url'] }}</div>
            @if($broker['error'])
                <div class="mt-1 text-xs text-red-500">{{ $broker['error'] }}</div>
            @endif
        </x-card>

        <x-card class="p-6">
            <div class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">Scheduler</div>
            {!! $badge($scheduler['ok'], 'Running', $scheduler['last'] ? 'Stalled' : 'Never ran') !!}
            <div class="mt-2 text-xs text-gray-500">
                Last beat {{ $scheduler['last']?->diffForHumans() ?? '—' }}
            </div>
        </x-card>

        <x-card class="p-6">
            <div class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">Queue worker</div>
            @if(!$scheduler['ok'])
                <span class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500"><span class="w-2 h-2 rounded-full bg-gray-400"></span>Unknown</span>
                <div class="mt-2 text-xs text-gray-500">Needs the scheduler to dispatch heartbeats</div>
            @else
                {!! $badge($queueWorker['ok'], 'Processing', 'Not processing') !!}
                <div class="mt-2 text-xs text-gray-500">Last job {{ $queueWorker['last']?->diffForHumans() ?? '—' }}</div>
            @endif
            <div class="mt-1 text-xs text-gray-500">
                {{ $pendingJobs['total'] ?? $queue['pending'] ?? '?' }} pending · {{ $queue['failed'] ?? '?' }} failed
            </div>
        </x-card>
    </div>

    {{-- Queue actions --}}
    <div class="flex flex-wrap gap-2" data-test="queue-actions">
        <x-secondary-button size="sm" wire:click="queueMissingEnrichment" wire:loading.attr="disabled" data-test="queue-missing"><i class="fa-solid fa-wand-magic-sparkles mr-2"></i>Queue missing metadata</x-secondary-button>
        <x-secondary-button size="sm" wire:click="restartWorkers" wire:confirm="Restart the queue workers? They finish their current job first." data-test="restart-workers"><i class="fa-solid fa-rotate mr-2"></i>Restart workers</x-secondary-button>
    </div>

    {{-- Failed jobs --}}
    @if($failedJobs)
        <x-card flush data-test="failed-jobs">
            <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Failed jobs <span class="text-sm font-normal text-gray-500">· {{ number_format($queue['failed'] ?? count($failedJobs)) }}</span></h2>
                <div class="flex gap-2">
                    <x-secondary-button size="sm" wire:click="retryAllFailed" data-test="retry-all">Retry all</x-secondary-button>
                    <x-secondary-button size="sm" wire:click="flushFailed" wire:confirm="Remove all failed jobs without retrying them?" data-test="flush-failed">Clear all</x-secondary-button>
                </div>
            </div>

            @foreach($failedJobs as $failed)
                <div class="grid grid-cols-2 sm:grid-cols-12 gap-x-4 gap-y-1 items-start px-8 py-3 border-b border-gray-50 dark:border-stone-800/50 last:border-0 text-sm" wire:key="failed-{{ $failed['id'] }}" data-test="failed-{{ $failed['id'] }}">
                    <div class="col-span-2 sm:col-span-3 min-w-0">
                        <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $failed['job'] }}</div>
                        <div class="text-xs text-gray-500">{{ $failed['queue'] }} · {{ $failed['at']->diffForHumans() }}</div>
                    </div>
                    <div class="col-span-2 sm:col-span-7 font-mono text-xs text-red-600 dark:text-red-400 break-all">{{ \Illuminate\Support\Str::limit($failed['message'], 200) }}</div>
                    <div class="col-span-2 sm:col-span-2 flex gap-3 sm:justify-end text-xs">
                        <button wire:click="retryFailed('{{ $failed['id'] }}')" class="text-gray-900 dark:text-gray-100 underline">Retry</button>
                        <button wire:click="forgetFailed('{{ $failed['id'] }}')" class="text-gray-500 hover:underline">Forget</button>
                    </div>
                </div>
            @endforeach

            @if(($queue['failed'] ?? 0) > count($failedJobs))
                <div class="px-8 py-3 text-xs text-gray-400 border-t border-gray-100 dark:border-stone-800">Showing the latest {{ count($failedJobs) }}.</div>
            @endif
        </x-card>
    @endif

    {{-- Pending jobs --}}
    @if($pendingJobs)
        <x-card flush data-test="pending-jobs">
            <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800 flex items-center justify-between">
                <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Pending jobs</h2>
                <span class="text-sm text-gray-500">{{ number_format($pendingJobs['total']) }} in queue</span>
            </div>

            @forelse($pendingJobs['groups'] as $group)
                <div class="grid grid-cols-2 sm:grid-cols-12 gap-x-4 gap-y-1 items-center px-8 py-3 border-b border-gray-50 dark:border-stone-800/50 last:border-0 text-sm">
                    <div class="col-span-2 sm:col-span-5 font-medium text-gray-900 dark:text-gray-100 truncate">{{ $group['job'] }}</div>
                    <div class="sm:col-span-2 text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($group['count']) }}</div>
                    <div class="sm:col-span-3 text-xs text-gray-500">
                        {{ $group['waiting'] }} waiting · {{ $group['running'] }} running · {{ $group['delayed'] }} delayed
                    </div>
                    <div class="sm:col-span-2 text-xs text-gray-500 sm:text-right">oldest {{ $group['oldest']?->diffForHumans(short: true) ?? '—' }}</div>
                </div>
            @empty
                <div class="px-8 py-8 text-center text-sm text-gray-500">No pending jobs.</div>
            @endforelse

            @if($pendingJobs['total'] > $pendingJobs['scanned'])
                <div class="px-8 py-3 text-xs text-gray-400 border-t border-gray-100 dark:border-stone-800">
                    Grouped from the oldest {{ number_format($pendingJobs['scanned']) }} of {{ number_format($pendingJobs['total']) }} jobs.
                </div>
            @endif
        </x-card>
    @endif

    {{-- Devices --}}
    <x-card flush>
        <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800">
            <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Devices</h2>
        </div>

        @forelse($devices as $row)
            @php($device = $row['device'])
            @php($stale = $row['listener_running'] && $row['last_seen'] && $row['last_seen']->diffInSeconds(now()) > 120)
            <div class="grid grid-cols-2 sm:grid-cols-12 gap-x-4 gap-y-1 items-center px-8 py-4 border-b border-gray-50 dark:border-stone-800/50 last:border-0">
                <div class="col-span-2 sm:col-span-4 min-w-0">
                    <a href="{{ route('devices.show', $device) }}" class="text-sm font-medium text-gray-900 dark:text-gray-100 hover:underline truncate block">{{ $device->device_name }}</a>
                    <div class="text-xs text-gray-400 dark:text-gray-600">{{ $device->device_driver_name ?: 'Unknown' }} · {{ $device->ip_address }}</div>
                </div>
                <div class="sm:col-span-3">
                    {!! $badge($row['listener_running'], 'Listener active', 'Listener down') !!}
                </div>
                <div class="sm:col-span-2 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                    <span class="w-2 h-2 rounded-full {{ $stateColors[$row['state']?->value] ?? 'bg-gray-400' }}"></span>
                    {{ ucfirst($row['state']?->value ?? 'unknown') }}
                </div>
                <div class="sm:col-span-2 text-xs {{ $stale ? 'text-amber-600 dark:text-amber-500' : 'text-gray-500' }}">
                    Seen {{ $row['last_seen']?->diffForHumans() ?? 'never' }}
                </div>
                <div class="sm:col-span-1 text-xs text-gray-500 sm:text-right">
                    @if($row['volume'] !== false)
                        <i class="fa-solid fa-volume-low mr-1"></i>{{ $row['volume'] }}
                    @endif
                </div>
            </div>
        @empty
            <div class="px-8 py-12 text-center text-sm text-gray-500">No devices registered yet.</div>
        @endforelse
    </x-card>
</div>
