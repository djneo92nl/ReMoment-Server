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

    {{-- Installed version and the last upgrade (php artisan app:upgrade) --}}
    <x-card class="p-6">
        <div class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">Version</div>
        <div class="text-sm font-medium text-gray-900 dark:text-gray-100" data-test="installed-version">{{ $installedVersion ?? config('app.version') }}</div>
        @if($upgrade)
            <div class="mt-2 text-xs text-gray-500" data-test="last-upgrade">
                Last upgrade {{ \Illuminate\Support\Carbon::parse($upgrade['at'])->diffForHumans() }}:
                {{ $upgrade['from'] ?? 'unknown' }} → {{ $upgrade['to'] }},
                {{ str_replace('_', ' ', $upgrade['result']) }}
            </div>
        @else
            <div class="mt-2 text-xs text-gray-500">No upgrade recorded. <span class="font-mono">php artisan app:upgrade</span> makes a backup first.</div>
        @endif
    </x-card>

    {{-- Diagnostics bundle (admin): one redacted zip to attach to an issue --}}
    @auth
        <x-card class="p-6">
            <div class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">Diagnostics</div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-gray-500 max-w-xl">One zip with versions, health, queues, devices and the log tail to attach to an issue. IP and MAC addresses, passwords, tokens and API keys are never included, and nothing is uploaded.</p>
                <a href="{{ route('settings.health.diagnostics') }}" class="shrink-0 text-sm font-medium underline hover:no-underline" data-test="download-diagnostics">Download diagnostics bundle</a>
            </div>
        </x-card>
    @endauth

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

        @if($relay['enabled'])
            <x-card class="p-6">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">Radio relay</div>
                {!! $badge($relay['manager'], 'Running', 'Not running') !!}
                <div class="mt-2 text-xs text-gray-500">
                    {{ collect($relay['data'])->where('state', 'running')->count() }} of {{ $relay['max'] }} running
                    @if($failing = collect($relay['data'])->where('state', 'restarting'))
                        @if($failing->count()) · <span class="text-amber-600 dark:text-amber-400">{{ $failing->count() }} restarting</span>@endif
                    @endif
                </div>
                @foreach($relay['data'] as $row)
                    <div class="mt-1 text-xs text-gray-500 truncate">{{ $row['station'] ?? '#'.$row['station_id'] }} · {{ $row['state'] }}@if($row['state'] === 'running') · {{ $row['listeners'] ?? 0 }} listening @endif</div>
                @endforeach
            </x-card>
        @endif

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

        @if($ipChanges)
            <div class="px-8 py-3 border-b border-gray-100 dark:border-stone-800 bg-amber-50/50 dark:bg-amber-500/5 text-xs text-gray-600 dark:text-gray-400" data-test="ip-changes">
                <span class="font-medium">Recent address changes:</span>
                @foreach($ipChanges as $change)
                    <div>{{ $change['name'] }}: <span class="font-mono">{{ $change['from'] ?? '—' }}</span> → <span class="font-mono">{{ $change['to'] ?? '—' }}</span>, {{ \Illuminate\Support\Carbon::createFromTimestamp($change['at'])->diffForHumans() }}</div>
                @endforeach
            </div>
        @endif

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
                @php($ls = $row['listener'])
                <div class="col-span-2 sm:col-span-12 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500" data-test="listener-detail-{{ $device->id }}">
                    <div class="min-w-0">
                        @if($ls)
                            <span>{{ ucfirst($ls['state']) }} since {{ \Illuminate\Support\Carbon::createFromTimestamp($ls['since'])->diffForHumans() }}@if($ls['ip']) · at <span class="font-mono">{{ $ls['ip'] }}</span>@endif</span>
                            @if($ls['reason'])<div class="text-amber-600 dark:text-amber-500">{{ $ls['reason'] }}@if($ls['retry_at'] && $ls['retry_at'] > time()) Retry in {{ $ls['retry_at'] - time() }}s.@endif @if($ls['failures'] > 1)({{ $ls['failures'] }} failures in a row)@endif</div>@endif
                            @if($ls['last_error'])<div class="text-red-600 dark:text-red-500 break-words">Last error {{ \Illuminate\Support\Carbon::createFromTimestamp($ls['last_error_at'])->diffForHumans() }}: {{ $ls['last_error'] }}</div>@endif
                            @if($ls['ip'] && $ls['ip'] !== $device->ip_address)<div class="text-amber-600 dark:text-amber-500">The device now has address {{ $device->ip_address }}; the listener will restart.</div>@endif
                        @elseif(!$row['listener_running'])
                            <span>No listener status yet: is <span class="font-mono">device:listen</span> running (see Supervisor above)?</span>
                        @endif
                    </div>
                    @auth
                        <button type="button" wire:click="restartListener({{ $device->id }})" class="shrink-0 text-xs font-medium underline hover:no-underline" data-test="restart-listener-{{ $device->id }}">Restart listener</button>
                    @endauth
                </div>
            </div>
        @empty
            <div class="px-8 py-12 text-center text-sm text-gray-500">No devices registered yet.</div>
        @endforelse
    </x-card>
</div>
