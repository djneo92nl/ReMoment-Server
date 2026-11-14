@php
    $bytes = fn ($n) => $n === null ? '—' : ($n >= 1073741824 ? round($n / 1073741824, 1).' GB' : round($n / 1048576).' MB');
    $duration = function ($s) {
        if ($s === null) return '—';
        return match (true) {
            $s >= 86400 => intdiv($s, 86400).'d '.intdiv($s % 86400, 3600).'h',
            $s >= 3600 => intdiv($s, 3600).'h '.intdiv($s % 3600, 60).'m',
            default => max(1, intdiv($s, 60)).'m',
        };
    };
@endphp

<div wire:poll.10s.visible class="space-y-6" data-test="manager-panel">

    @if($notice)
        <div class="px-5 py-3 text-sm rounded-2xl border text-emerald-800 dark:text-emerald-200 bg-emerald-50 dark:bg-emerald-950 border-emerald-200 dark:border-emerald-800" data-test="manager-notice">
            <i class="fa-solid fa-circle-check mr-2"></i>{{ $notice }}
        </div>
    @endif
    @if($failure)
        <div class="px-5 py-3 text-sm rounded-2xl border text-red-800 dark:text-red-200 bg-red-50 dark:bg-red-950 border-red-200 dark:border-red-800" data-test="manager-failure">
            <i class="fa-solid fa-triangle-exclamation mr-2"></i>{{ $failure }}
        </div>
    @endif

    @if($error)
        <x-card data-test="manager-unavailable">
            <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">The manager is not available</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $error }}</p>
            @if($health)
                <p class="mt-2 text-xs text-gray-500">It does answer (version {{ $health['version'] ?? '?' }}), so this is about the token.</p>
            @endif
            <p class="mt-3 text-xs text-gray-500">The manager is a service on the host, next to Docker, so it keeps working when this app does not: <code>remoment-manager status</code> on the server.</p>
        </x-card>
    @else
        <div class="flex flex-wrap items-center gap-2">
            <x-secondary-button size="sm" wire:click="restartAll"
                                wire:confirm="Restart every container, this app included? The page stops answering for a moment."
                                data-test="restart-all"><i class="fa-solid fa-rotate mr-2"></i>Restart all</x-secondary-button>
            <span class="text-xs text-gray-500">Project {{ $status['project'] }} · refreshes every 10 s</span>
        </div>

        <x-card flush data-test="manager-services">
            <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800">
                <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Containers</h2>
            </div>
            @foreach($status['services'] as $service)
                @php
                    $bad = $service['problem'] !== null || $service['state'] === 'missing' || ($service['health'] ?? null) === 'unhealthy';
                    $dot = $bad ? 'bg-red-500' : ($service['state'] === 'running' ? 'bg-emerald-500' : 'bg-gray-400');
                @endphp
                <div class="px-8 py-3 flex flex-wrap items-center gap-x-5 gap-y-1 border-b border-gray-100 dark:border-stone-800 last:border-b-0" data-test="service-{{ $service['service'] }}">
                    <div class="flex items-center gap-2 w-40 text-sm font-medium text-gray-900 dark:text-gray-100">
                        <span class="w-1.5 h-1.5 rounded-full {{ $dot }}"></span>{{ $service['service'] }}
                    </div>
                    <div class="w-44 text-sm {{ $bad ? 'text-red-600' : 'text-gray-600 dark:text-gray-400' }}">
                        {{ $service['problem'] ?? $service['state'] }}@if($service['health'] ?? null) · {{ $service['health'] }}@endif
                        @if(($service['state'] ?? '') === 'exited' && isset($service['exit_code'])) (exit {{ $service['exit_code'] }})@endif
                    </div>
                    <div class="flex-1 min-w-40 text-xs text-gray-500">
                        up {{ $duration($service['up_seconds'] ?? null) }} · {{ $service['restart_count'] ?? 0 }} restarts
                        · {{ $service['memory_usage'] ?? $bytes($service['memory_bytes'] ?? null) }} · cpu {{ isset($service['cpu_percent']) ? round($service['cpu_percent'], 1).'%' : '—' }}
                    </div>
                    <div class="flex gap-2">
                        <x-secondary-button size="sm" wire:click="showLogs('{{ $service['service'] }}')" data-test="logs-{{ $service['service'] }}">Logs</x-secondary-button>
                        <x-secondary-button size="sm" wire:click="restart('{{ $service['service'] }}')"
                                            wire:confirm="Restart {{ $service['service'] }}?{{ $service['service'] === 'app' ? ' This is the app serving this page: it stops answering for a moment.' : '' }}"
                                            data-test="restart-{{ $service['service'] }}">Restart</x-secondary-button>
                    </div>
                </div>
            @endforeach
        </x-card>

        @if($logService)
            <x-card flush data-test="manager-logs">
                <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800 flex items-center justify-between">
                    <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Logs · {{ $logService }} <span class="text-sm font-normal text-gray-500">last {{ $logTail }} lines</span></h2>
                    <x-secondary-button size="sm" wire:click="closeLogs">Close</x-secondary-button>
                </div>
                <pre class="px-8 py-4 max-h-96 overflow-auto text-xs leading-relaxed text-gray-700 dark:text-gray-300 whitespace-pre-wrap break-all">{{ $logs === null || trim($logs) === '' ? 'No output.' : $logs }}</pre>
            </x-card>
        @endif

        @php $host = $status['host']; @endphp
        <x-card data-test="manager-host">
            <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Host</h2>
            <dl class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-3 text-sm">
                <div><dt class="text-xs text-gray-500">Disk free</dt><dd class="text-gray-900 dark:text-gray-100">{{ $host['disk'] ? $bytes($host['disk']['free_bytes']).' of '.$bytes($host['disk']['total_bytes']) : '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Memory free</dt><dd class="text-gray-900 dark:text-gray-100">{{ $bytes($host['memory']['free_bytes'] ?? null) }} of {{ $bytes($host['memory']['total_bytes'] ?? null) }}</dd></div>
                <div><dt class="text-xs text-gray-500">Load (1, 5, 15 min)</dt><dd class="text-gray-900 dark:text-gray-100">{{ implode('  ', array_map(fn ($l) => number_format($l, 2), $host['load'] ?? [])) ?: '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Uptime</dt><dd class="text-gray-900 dark:text-gray-100">{{ $duration($host['uptime_seconds'] ?? null) }}</dd></div>
                @if(($host['cpu_temperature_c'] ?? null) !== null)
                    <div><dt class="text-xs text-gray-500">CPU temperature</dt><dd class="text-gray-900 dark:text-gray-100">{{ round($host['cpu_temperature_c']) }} °C</dd></div>
                @endif
            </dl>
        </x-card>

        <x-card flush data-test="manager-audit">
            <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800">
                <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Recent changes</h2>
            </div>
            @forelse($audit as $entry)
                <div class="px-8 py-3 flex flex-wrap gap-x-5 text-sm border-b border-gray-100 dark:border-stone-800 last:border-b-0">
                    <span class="w-44 text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($entry['time'])->diffForHumans() }}</span>
                    <span class="text-gray-900 dark:text-gray-100">{{ $entry['action'] }}@if($entry['service']) · {{ $entry['service'] }}@endif</span>
                    <span class="text-xs {{ str_starts_with($entry['result'], 'failed') ? 'text-red-600' : 'text-gray-500' }}">{{ $entry['result'] }}</span>
                </div>
            @empty
                <div class="px-8 py-8 text-center text-sm text-gray-500">No changes made through the manager yet.</div>
            @endforelse
        </x-card>
    @endif
</div>
