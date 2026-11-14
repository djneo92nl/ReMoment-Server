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

<div @if($job['running'] ?? false) wire:poll.2s @else wire:poll.10s.visible @endif class="space-y-6" data-test="manager-panel">

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


        @if($job)
            @php
                $record = ($job['running'] ?? false) && ($job['rollback']['state'] ?? null) === 'running' ? $job['rollback'] : ($job['upgrade'] ?? null);
                $last = collect([$job['upgrade'] ?? null, $job['rollback'] ?? null])->filter()->sortByDesc('started_at')->first();
                $icon = fn ($r) => match ($r) { 'ok' => 'fa-circle-check text-emerald-600', 'failed' => 'fa-circle-xmark text-red-600', default => 'fa-minus text-gray-400' };
            @endphp
            <x-card flush data-test="manager-upgrade">
                <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Upgrade</h2>
                        <p class="text-xs text-gray-500">Installs the new image ({{ $job['mode'] === 'pull' ? 'pulled' : 'built from the code on disk' }}), makes a backup, migrates and checks the app. Install the new code first.</p>
                    </div>
                    <div class="flex gap-2">
                        @if($job['can_rollback'] && ! $job['running'])
                            <x-secondary-button size="sm" wire:click="rollback"
                                                wire:confirm="Roll back the last failed upgrade? The previous image comes back and the backup made just before it is restored: anything saved after that backup is lost."
                                                data-test="rollback"><i class="fa-solid fa-rotate-left mr-2"></i>Roll back</x-secondary-button>
                        @endif
                        <x-secondary-button size="sm" wire:click="upgrade" :disabled="$job['running']"
                                            wire:confirm="Upgrade now? The app is unavailable while it migrates and may stay in maintenance mode if that fails. A backup is made first."
                                            data-test="upgrade"><i class="fa-solid fa-arrow-up-from-bracket mr-2"></i>Upgrade</x-secondary-button>
                    </div>
                </div>
                @if($last)
                    <div class="px-8 py-4 space-y-3 text-sm" data-test="upgrade-result">
                        <div class="flex flex-wrap items-center gap-x-3 text-gray-900 dark:text-gray-100">
                            <span class="font-medium">{{ ucfirst($last['kind']) }}</span>
                            @if($last['state'] === 'running')
                                <span class="text-amber-600"><i class="fa-solid fa-spinner fa-spin mr-1"></i>running</span>
                            @elseif($last['state'] === 'ok')
                                <span class="text-emerald-700 dark:text-emerald-300">done</span>
                            @else
                                <span class="text-red-600">failed: {{ str_replace('_', ' ', $last['exit_reason'] ?? 'unknown') }}</span>
                            @endif
                            <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($last['started_at'])->diffForHumans() }}</span>
                        </div>
                        @if($last['message'] ?? null)<p class="text-gray-600 dark:text-gray-400">{{ $last['message'] }}</p>@endif
                        @foreach($last['steps'] ?? [] as $s)
                            <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400"><i class="fa-solid {{ $icon($s['result']) }}"></i>{{ $s['name'] }}<span class="text-gray-400">{{ $s['seconds'] }} s</span></div>
                        @endforeach
                        @if(($last['kind'] ?? '') === 'upgrade' && ($last['state'] ?? '') === 'failed' && ! empty($last['rollback']['commands']))
                            <p class="text-xs text-gray-500">By hand: <code>{{ implode(' && ', $last['rollback']['commands']) }}</code></p>
                        @endif
                        @if(! empty($job['log']) && $job['running'])
                            <pre class="max-h-48 overflow-auto text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap" data-test="upgrade-log">{{ collect($job['log'])->pluck('line')->implode("\n") }}</pre>
                        @endif
                    </div>
                @endif
            </x-card>
        @endif

        @if($smb)
            <x-card flush data-test="manager-smb">
                <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800">
                    <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Network shares (SMB)</h2>
                    <p class="text-xs text-gray-500">Samba on the host, set up by the manager. Access needs the share user's password unless you open a share to guests (off by default; guests also need <code>map to guest = Bad User</code> in smb.conf).
                        Samba is {{ $smb['running'] ? 'running' : 'not running' }}@if($smb['sessions'] !== null) · {{ $smb['sessions']['count'] }} connected @endif.
                        @if($smb['dry_run']) <span class="text-amber-600">The manager is in dry-run mode: nothing is changed.</span>@endif</p>
                </div>

                @foreach($smb['problems'] as $problem)
                    <div class="px-8 py-3 text-sm text-red-600 border-b border-gray-100 dark:border-stone-800">{{ $problem }}</div>
                @endforeach
                @if(! $smb['include_present'])
                    <div class="px-8 py-3 text-xs text-amber-700 border-b border-gray-100 dark:border-stone-800" data-test="smb-include">smb.conf does not include <code>{{ $smb['conf_file'] }}</code> yet: add <code>include = {{ $smb['conf_file'] }}</code> to its [global] section, or the shares below are not served.</div>
                @endif

                @if($sharePassword)
                    <div class="px-8 py-4 text-sm bg-amber-50 dark:bg-amber-950 border-b border-amber-200 dark:border-amber-800" data-test="smb-password">
                        <p class="text-gray-900 dark:text-gray-100">Share user <strong>{{ $smb['user']['name'] ?? '' }}</strong>, password <code class="select-all font-mono">{{ $sharePassword }}</code></p>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">Shown once: it is not stored anywhere readable, so note it now.</p>
                        <x-secondary-button size="sm" class="mt-2" wire:click="dismissSharePassword" data-test="smb-password-done">I have noted it</x-secondary-button>
                    </div>
                @endif

                <div class="px-8 py-3 flex flex-wrap items-center gap-3 border-b border-gray-100 dark:border-stone-800" data-test="smb-user">
                    <div class="flex-1 text-sm text-gray-700 dark:text-gray-300">
                        Share user:
                        @if($smb['user'])<strong>{{ $smb['user']['name'] }}</strong> (separate from the admin login)@else <span class="text-gray-500">none yet; create it before switching a share on</span>@endif
                    </div>
                    @if($smb['user'])
                        <x-secondary-button size="sm" wire:click="createShareUser" wire:confirm="Set a new password for the share user? Machines using the old one are signed out." data-test="smb-user-create">New password</x-secondary-button>
                    @else
                        <x-secondary-button size="sm" wire:click="createShareUser" data-test="smb-user-create">Create user</x-secondary-button>
                    @endif
                    @if($smb['user'])
                        <x-secondary-button size="sm" wire:click="removeShareUser" wire:confirm="Remove the share user? Switch every share off first." data-test="smb-user-remove">Remove</x-secondary-button>
                    @endif
                </div>

                @foreach($smb['shares'] as $share)
                    <div class="px-8 py-3 flex flex-wrap items-center gap-x-5 gap-y-1 border-b border-gray-100 dark:border-stone-800 last:border-b-0" data-test="smb-share-{{ $share['name'] }}">
                        <div class="w-44 text-sm font-medium text-gray-900 dark:text-gray-100">
                            <span class="inline-block w-1.5 h-1.5 rounded-full {{ $share['enabled'] ? 'bg-emerald-500' : 'bg-gray-400' }} mr-2"></span>{{ $share['label'] }}
                        </div>
                        <div class="flex-1 min-w-48 text-xs text-gray-500">
                            <code>{{ $share['path'] }}</code>@if(! $share['path_exists']) <span class="text-amber-600">(folder missing)</span>@endif
                            @if($share['path_error']) <span class="text-red-600">{{ $share['path_error'] }}</span>@endif
                            · {{ $share['enabled'] ? ($share['writable'] ? 'read-write' : 'read-only') : 'off' }}@if($share['enabled'] && $share['guest']) · <span class="text-amber-600" data-test="smb-guest-on-{{ $share['name'] }}">open to guests, no password</span>@endif
                            <br>{{ $share['note'] }}
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if($share['can_set_writable'])
                                @if($share['writable'])
                                    <x-secondary-button size="sm" wire:click="smbShare('{{ $share['name'] }}', true, false)" :disabled="! $share['enabled']" data-test="smb-writable-{{ $share['name'] }}">Make read-only</x-secondary-button>
                                @else
                                    <x-secondary-button size="sm" wire:click="smbShare('{{ $share['name'] }}', true, true)" wire:confirm="Allow writing to the music library over the network? A mistake on a PC can delete music." :disabled="! $share['enabled']" data-test="smb-writable-{{ $share['name'] }}">Allow writing</x-secondary-button>
                                @endif
                            @endif
                            @if($share['guest'])
                                <x-secondary-button size="sm" wire:click="smbGuest('{{ $share['name'] }}', false)" :disabled="! $share['enabled']" data-test="smb-guest-{{ $share['name'] }}">Require password</x-secondary-button>
                            @else
                                <x-secondary-button size="sm" wire:click="smbGuest('{{ $share['name'] }}', true)" wire:confirm="Open this share to everyone on the network, without a password? Anyone who can reach this machine can read it{{ $share['writable'] ? ' and write to it' : '' }}." :disabled="! $share['enabled']" data-test="smb-guest-{{ $share['name'] }}">Allow guests</x-secondary-button>
                            @endif
                            <x-secondary-button size="sm" wire:click="smbPreviewShare('{{ $share['name'] }}', {{ $share['enabled'] ? 'false' : 'true' }})" data-test="smb-preview-{{ $share['name'] }}">Preview</x-secondary-button>
                            <x-secondary-button size="sm" wire:click="smbShare('{{ $share['name'] }}', {{ $share['enabled'] ? 'false' : 'true' }})"
                                                :disabled="! $share['enabled'] && ! $smb['user'] && ! $share['guest']" data-test="smb-toggle-{{ $share['name'] }}">{{ $share['enabled'] ? 'Switch off' : 'Switch on' }}</x-secondary-button>
                        </div>
                    </div>
                @endforeach

                @if($smbPreview !== null)
                    <div class="px-8 py-4 border-t border-gray-100 dark:border-stone-800" data-test="smb-preview">
                        <div class="flex items-center justify-between"><h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">What the Samba file would contain</h3>
                            <x-secondary-button size="sm" wire:click="closeSmbPreview">Close</x-secondary-button></div>
                        <pre class="mt-2 max-h-72 overflow-auto text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $smbPreview }}</pre>
                    </div>
                @endif
            </x-card>
        @endif

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
