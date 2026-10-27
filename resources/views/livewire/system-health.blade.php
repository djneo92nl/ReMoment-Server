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

    {{-- Active alerts (App\Domain\Alerts\Alerts); the backup one has its own notice below. --}}
    @foreach($alerts->where('kind', '!=', 'backup.stale') as $alert)
        <x-alert :type="['critical' => 'error', 'warning' => 'warning', 'info' => 'info'][$alert->severity] ?? 'info'">
            <span class="font-medium">{{ $alert->title }}.</span> {{ $alert->message }}
            <span class="text-xs opacity-75">Since {{ $alert->raised_at->diffForHumans() }}{{ $alert->acknowledged_at ? ', acknowledged' : '' }}</span>
            @if(!$alert->acknowledged_at && auth()->check())
                <x-slot:action>
                    <button type="button" wire:click="acknowledge({{ $alert->id }})" class="text-xs font-medium underline hover:no-underline">Acknowledge</button>
                </x-slot:action>
            @endif
        </x-alert>
    @endforeach

    @unless($backup['ok'])
        <x-alert type="warning">
            {{ $backup['last'] ? 'The last backup is from '.$backup['last']->diffForHumans().'.' : 'No backup exists yet.' }}
            The nightly <span class="font-mono">backup:run</span> keeps the database and settings safe; run it by hand or check the scheduler.
        </x-alert>
    @endunless

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
