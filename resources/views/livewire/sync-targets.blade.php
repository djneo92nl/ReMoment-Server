@php
    $size = fn ($bytes) => $bytes >= 1000 ** 3 ? number_format($bytes / 1000 ** 3, 2).' GB' : number_format($bytes / 1000 ** 2, 1).' MB';
    $anyRunning = $targets->contains(fn ($t) => $t['running']);
@endphp
<div class="space-y-6" @if($anyRunning) wire:poll.3s @else wire:poll.15s @endif data-test="sync-targets">
    @forelse($targets as $t)
        @php
            $r = $t['resource'];
            $id = $r['id'];
            $run = $r['last_run'];
            $scan = $t['scan'];
            $marker = $scan['marker'] ?? null;
        @endphp
        <x-card class="space-y-4 p-5 sm:p-6" data-test="sync-target-{{ $id }}">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="min-w-0 flex-1 truncate text-base font-medium tracking-tight text-gray-900 dark:text-gray-100"><i class="fa-solid fa-hard-drive mr-1.5 text-gray-400"></i>{{ $r['name'] }}</h2>
                <x-badge variant="outline">{{ $r['node']['name'] ?? 'Node #'.$r['node_id'] }}</x-badge>
                <x-badge variant="outline">{{ $profiles[$r['rules']['profile']] ?? $r['rules']['profile'] }}</x-badge>
                @if($t['running'])<x-badge variant="info" data-test="running">{{ $t['job_state'] === 'queued' ? 'Waiting for the node' : 'Working' }}</x-badge>@endif
            </div>

            <p class="text-sm text-gray-600 dark:text-gray-400" data-test="summary">{{ $t['summary'] }}@if($r['rules']['budget']['bytes']), up to {{ $size($r['rules']['budget']['bytes']) }}@endif · <span class="text-gray-500">holds {{ number_format($r['owned_count']) }} files ({{ $size($r['owned_bytes']) }})</span></p>

            @if($t['task'] && $t['job_type'] === 'sync.apply')
                <div>
                    <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-stone-800"><div class="h-full rounded-full bg-gray-900 dark:bg-gray-100" style="width: {{ $t['task']['percent'] ?? 0 }}%"></div></div>
                    <p class="mt-1 text-xs text-gray-500" data-test="progress">{{ $t['task']['detail'] ?? 'Starting…' }} files{{ $t['task']['percent'] !== null ? ' · '.$t['task']['percent'].'%' : '' }}</p>
                </div>
            @endif

            @if($scan)
                <div class="rounded-2xl border border-gray-100 p-4 text-sm dark:border-stone-800" data-test="drive">
                    <p class="font-medium text-gray-900 dark:text-gray-100">Drive on the node</p>
                    <p class="mt-1 text-gray-600 dark:text-gray-400">
                        @if(!($scan['mounted'] ?? true))
                            No drive found. Plug it in and check again.
                        @else
                            {{ strtoupper($scan['fs'] ?? '?') }}, {{ $size($scan['free'] ?? 0) }} free of {{ $size($scan['size'] ?? 0) }}.
                            @if($marker === null)
                                Not used by ReMoment yet. <strong class="font-medium">Use this drive</strong> to mark it for this target; nothing is written before that.
                            @elseif(($marker['target_id'] ?? null) === $id)
                                Marked for this target{{ !empty($scan['manifest']) ? ', holds '.number_format($scan['manifest']['entries'] ?? 0).' files of ours' : '' }}.
                            @else
                                Belongs to another target or server. It will not be written to.
                            @endif
                        @endif
                    </p>
                </div>
            @elseif($t['scan_state'])
                <p class="text-xs text-gray-500" data-test="scan-state">Drive check: {{ $t['scan_state'] }}</p>
            @endif

            @if($run)
                @php
                    $ok = $run['state'] === 'succeeded';
                    $needs = ($run['result_state'] ?? null) === 'needs_confirmation';
                @endphp
                <div class="text-sm" data-test="last-run">
                    <x-badge :variant="$needs ? 'warning' : ($ok ? 'success' : ($run['state'] === 'cancelled' ? 'outline' : 'danger'))">{{ $needs ? 'Needs your yes' : ucfirst($run['state']) }}</x-badge>
                    <span class="ml-2 text-gray-600 dark:text-gray-400">
                        {{ \Illuminate\Support\Carbon::parse($run['finished_at'])->diffForHumans() }}
                        {{ $ok && !$needs ? ': '.($run['summary']['applied'] ?? 0).' added or replaced, '.($run['summary']['removed'] ?? 0).' removed, '.($run['summary']['failed'] ?? 0).' failed' : '' }}
                    </span>
                    @if($needs)
                        <p class="mt-1 text-xs text-gray-500">This sync would remove many files, so nothing was removed. Tick the box and sync again if that is what you want.</p>
                    @endif
                    @if(!$ok && !empty($run['error']))<p class="mt-1 text-xs text-gray-500">{{ $run['error'] }}</p>@endif
                    @if(!empty($run['summary']['warnings']))<p class="mt-1 text-xs text-gray-500">Warnings: {{ implode(', ', array_map(fn ($w) => is_array($w) ? ($w['code'] ?? json_encode($w)) : $w, $run['summary']['warnings'])) }}</p>@endif
                </div>
            @endif

            @if(isset($estimates[$id]))
                @php $e = $estimates[$id]; @endphp
                <div class="rounded-2xl border border-gray-100 p-4 text-sm text-gray-600 dark:border-stone-800 dark:text-gray-400" data-test="estimate">
                    {{ number_format($e['tracks']) }} tracks selected ({{ $size($e['bytes']) }}); {{ number_format($e['wanted']) }} would go on the drive ({{ $size($e['wanted_bytes']) }}),
                    {{ $e['copied'] }} copied as they are, {{ $e['transcoded'] }} converted, {{ $e['skipped'] }} skipped (no file of our own).
                    @if($e['over_budget'])<x-alert type="warning" class="mt-2">Over the budget. Raise it, pick less, or use the priority fill.</x-alert>@endif
                </div>
            @endif

            @if(isset($notes[$id]))<p class="text-xs text-gray-500" data-test="note">{{ $notes[$id] }}</p>@endif

            <div class="flex flex-wrap items-center gap-2">
                <x-primary-button size="sm" wire:click="sync('{{ $id }}')" :disabled="$t['running']" data-test="sync-now"><i class="fa-solid fa-arrows-rotate"></i>Sync now</x-primary-button>
                @if($t['running'])
                    <x-secondary-button size="sm" wire:click="cancelRun('{{ $id }}')" data-test="cancel-run"><i class="fa-solid fa-xmark"></i>Cancel</x-secondary-button>
                @endif
                <x-secondary-button size="sm" wire:click="scan('{{ $id }}')" :disabled="$t['running']" data-test="scan">Check drive</x-secondary-button>
                @if($scan && $marker === null && ($scan['mounted'] ?? true))
                    <x-secondary-button size="sm" wire:click="claim('{{ $id }}')" :disabled="$t['running']" data-test="claim">Use this drive</x-secondary-button>
                @endif
                <x-secondary-button size="sm" wire:click="estimate('{{ $id }}')" data-test="estimate-btn">Estimate</x-secondary-button>
                <x-secondary-button size="sm" wire:click="edit('{{ $id }}')" data-test="edit">Rules</x-secondary-button>
                <label class="ml-1 flex items-center gap-1.5 text-xs text-gray-500"><input type="checkbox" wire:model="confirm" class="rounded"> allow removing many files</label>
                <x-secondary-button size="sm" class="ml-auto" wire:click="delete('{{ $id }}')" wire:confirm="Delete this target? Files on the drive stay where they are." data-test="delete"><i class="fa-solid fa-trash"></i>Delete</x-secondary-button>
            </div>

            @if($editing === $id)
                <div class="space-y-4 rounded-2xl border border-gray-100 p-4 dark:border-stone-800" data-test="rules-form">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="profile-{{ $id }}" value="Quality" />
                            <x-select id="profile-{{ $id }}" wire:model="draft.profile" class="w-full">@foreach($profiles as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-select>
                        </div>
                        <div>
                            <x-input-label for="loudness-{{ $id }}" value="Loudness" />
                            <x-select id="loudness-{{ $id }}" wire:model="draft.loudness" class="w-full">
                                <option value="off">Keep the original level</option><option value="album">Level by album</option><option value="track">Level every track</option>
                            </x-select>
                        </div>
                        <div>
                            <x-input-label for="budget-{{ $id }}" value="Size budget (GB, empty = what fits)" />
                            <x-text-input id="budget-{{ $id }}" type="number" step="0.5" min="0" wire:model="draft.budget_gb" class="w-full" />
                        </div>
                        <div>
                            <x-input-label for="fill-{{ $id }}" value="When it does not all fit" />
                            <x-select id="fill-{{ $id }}" wire:model="draft.fill" class="w-full">
                                <option value="all">Refuse (everything or nothing)</option>
                                <option value="priority">In the order below, whole albums</option>
                                <option value="fill_rest">In that order, then fill the rest with favourites, most played, newest</option>
                            </x-select>
                        </div>
                    </div>
                    <div>
                        <x-input-label value="Playlists (in this order)" />
                        <div class="mt-1 grid max-h-48 gap-1 overflow-y-auto sm:grid-cols-2">
                            @foreach($playlists as $p)
                                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" value="{{ $p->id }}" wire:model="draft.playlists" class="rounded"> {{ $p->name }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="draft.favorites" class="rounded"> Favourite albums and artists</label>
                        <div><x-input-label for="recent-{{ $id }}" value="Newest tracks (how many)" /><x-text-input id="recent-{{ $id }}" type="number" min="1" wire:model="draft.recent" class="w-full" /></div>
                        <div><x-input-label for="played-{{ $id }}" value="Most played (how many)" /><x-text-input id="played-{{ $id }}" type="number" min="1" wire:model="draft.played" class="w-full" /></div>
                    </div>
                    @if($error)<x-alert type="error" data-test="rules-error">{{ $error }}</x-alert>@endif
                    <div class="flex gap-2">
                        <x-primary-button size="sm" wire:click="save" data-test="save-rules">Save rules</x-primary-button>
                        <x-secondary-button size="sm" wire:click="cancelEdit">Close</x-secondary-button>
                    </div>
                </div>
            @endif
        </x-card>
    @empty
        <x-empty-state data-test="no-targets" icon="hard-drive" title="No sync target yet" text="Add a drive on a node below." />
    @endforelse

    <x-card class="space-y-3 p-5 sm:p-6" data-test="new-target">
        <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Add a drive</h2>
        @if($freeNodes->isEmpty())
            <p class="text-sm text-gray-500">No node with the sync-target role is available. Register a ReMoment Node, approve it at Settings › Clients and switch the sync-target role on.</p>
        @else
            <div class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <div><x-input-label for="new-name" value="Name" /><x-text-input id="new-name" wire:model="newName" class="w-full" placeholder="Car stick" /></div>
                <div>
                    <x-input-label for="new-node" value="Node it is plugged into" />
                    <x-select id="new-node" wire:model="newNode" class="w-full"><option value="">Choose a node</option>@foreach($freeNodes as $n)<option value="{{ $n->id }}">{{ $n->name ?? 'Node #'.$n->id }}</option>@endforeach</x-select>
                </div>
                <x-primary-button size="md" wire:click="create" data-test="create-target">Add</x-primary-button>
            </div>
        @endif
        @if($error && !$editing)<x-alert type="error" data-test="create-error">{{ $error }}</x-alert>@endif
    </x-card>
</div>
