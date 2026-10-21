<div wire:poll.5s class="space-y-6" data-test="analysis-status">

    @if(session('analysis-success'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 6000)" x-show="show" x-transition
             style="position: fixed; right: 1.5rem; bottom: 1.5rem; z-index: 60; max-width: 24rem;"
             class="px-5 py-3 text-sm rounded-2xl shadow-xl border text-emerald-800 dark:text-emerald-200 bg-emerald-50 dark:bg-emerald-950 border-emerald-200 dark:border-emerald-800"
             data-test="analysis-toast">
            <i class="fa-solid fa-circle-check mr-2"></i>{{ session('analysis-success') }}
        </div>
    @endif

    @unless($progress['enabled'])
        <x-alert type="warning" data-test="analysis-off">Audio analysis is off. Set <code>ANALYSIS_ENABLED=true</code> in <code>.env</code> to start it (see <code>docs/architecture/audio-analysis.md</code>); the numbers below show what is already stored.</x-alert>
    @endunless

    {{-- Summary --}}
    <x-card class="p-6 sm:p-8" data-test="analysis-summary">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-6">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Own files</div>
                <div class="mt-1 text-2xl font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($progress['files']) }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Fully analysed</div>
                <div class="mt-1 text-2xl font-medium tabular-nums text-gray-900 dark:text-gray-100" data-test="complete">{{ number_format($progress['complete']) }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Waiting</div>
                <div class="mt-1 text-2xl font-medium tabular-nums text-gray-900 dark:text-gray-100" data-test="backlog">{{ number_format($progress['backlog']) }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">With problems</div>
                <div class="mt-1 text-2xl font-medium tabular-nums {{ $progress['with_problems'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-gray-100' }}" data-test="with-problems">{{ number_format($progress['with_problems']) }}</div>
            </div>
        </div>

        <dl class="mt-6 pt-6 border-t border-gray-100 dark:border-stone-800 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
            <div class="flex justify-between gap-4"><dt class="text-gray-500">In the analysis queue</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100" data-test="queued">{{ number_format($queued) }} jobs</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-gray-500">Last 24 hours</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100" data-test="per-day">{{ number_format($perDay) }} files</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-gray-500">Schedule</dt><dd class="text-gray-900 dark:text-gray-100">nightly 02:00 · {{ config('analysis.batch') }} files · {{ config('analysis.scan_batch') }} after a scan</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-gray-500">Last activity</dt><dd class="text-gray-900 dark:text-gray-100">{{ $lastRun ? \Illuminate\Support\Carbon::parse($lastRun)->diffForHumans() : 'never' }}</dd></div>
        </dl>

        @if($progress['enabled'] && $progress['backlog'] > 0)
            <p class="mt-4 text-sm text-gray-500" data-test="eta">
                At {{ config('analysis.batch') }} files a night the {{ number_format($progress['backlog']) }} waiting files take about {{ $nights }} {{ \Illuminate\Support\Str::plural('night', $nights) }}.
                @if($perDay > 0) The last 24 hours did {{ number_format($perDay) }}. @endif
            </p>
        @endif

        @if($progress['enabled'])
            <div class="mt-5">
                <x-secondary-button size="sm" wire:click="runBacklog" wire:loading.attr="disabled" data-test="run-backlog"><i class="fa-solid fa-play mr-2"></i>Queue a batch now</x-secondary-button>
            </div>
        @endif
    </x-card>

    {{-- Now processing --}}
    <x-card compact class="px-6 py-4" data-test="analysis-now">
        <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Now</div>
        @if($current)
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                <x-badge variant="info"><i class="fa-solid fa-gear fa-spin"></i>{{ $current['stage'] === 'decode' ? 'shared decode' : $current['stage'] }}</x-badge>
                <span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100" title="{{ $current['path'] }}">{{ $currentFile?->path ?? $current['path'] }}</span>
                <span class="text-gray-500 tabular-nums">{{ \Illuminate\Support\Carbon::createFromTimestamp($current['started_at'])->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</span>
            </div>
        @else
            <p class="mt-1 text-sm text-gray-500">{{ $queued > 0 ? 'Waiting for the worker to pick up the next file.' : 'Idle.' }}</p>
        @endif
    </x-card>

    {{-- Pipeline --}}
    <x-card flush data-test="analysis-stages">
        <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800">
            <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Stages</h2>
            <p class="mt-1 text-sm text-gray-500">Run in this order on every file; a failing stage does not stop the others. Loudness, waveform, quality and Essentia share one decode of the file.</p>
        </div>
        @foreach($progress['stages'] as $key => $row)
            @php($total = max(1, $progress['files']))
            <div class="px-8 py-4 border-b border-gray-50 dark:border-stone-800/50 last:border-0 {{ $row['enabled'] ? '' : 'opacity-60' }}" data-test="stage-{{ $key }}">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ ucfirst($key) }}</span>
                    <span class="text-xs text-gray-400 dark:text-gray-600">v{{ $row['version'] }}</span>
                    @unless($row['enabled'])
                        <x-badge variant="outline" data-test="stage-disabled-{{ $key }}">not running: {{ $row['reason'] }}</x-badge>
                    @endunless
                    <span class="ml-auto text-sm tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($row['done']) }} <span class="text-gray-400">/ {{ number_format($progress['files']) }}</span></span>
                </div>
                <p class="mt-0.5 text-sm text-gray-500">{{ $row['description'] }}@if(isset($durations[$key])) · about {{ $durations[$key] }} s a file @endif</p>
                <div class="mt-2 flex h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-stone-800" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $progress['files'] }}" aria-valuenow="{{ $row['done'] }}" aria-label="{{ $key }} done">
                    <div class="bg-emerald-500" style="width: {{ $row['done'] / $total * 100 }}%"></div>
                    <div class="bg-amber-400" style="width: {{ $row['failed'] / $total * 100 }}%"></div>
                    <div class="bg-red-500" style="width: {{ $row['gave_up'] / $total * 100 }}%"></div>
                </div>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                    <span data-test="pending-{{ $key }}">{{ number_format($row['pending']) }} waiting @if($row['stale'])({{ number_format($row['stale']) }} to redo)@endif</span>
                    @if($row['failed'])<span class="text-amber-600 dark:text-amber-400" data-test="failed-{{ $key }}">{{ number_format($row['failed']) }} failed, will retry</span>@endif
                    @if($row['gave_up'])<span class="text-red-600 dark:text-red-400" data-test="gave-up-{{ $key }}">{{ number_format($row['gave_up']) }} gave up after {{ config('analysis.max_attempts') }} tries</span>@endif
                </div>
            </div>
        @endforeach
        @if(isset($durations['decode']))
            <div class="px-8 py-3 text-xs text-gray-400 border-t border-gray-100 dark:border-stone-800">The shared decode takes about {{ $durations['decode'] }} s a file; the stage times above come on top of it.</div>
        @endif
    </x-card>

    {{-- Sidecar --}}
    @if($sidecar)
        <x-card compact class="px-6 py-4" data-test="analysis-sidecar">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Essentia sidecar</h2>
                @if($sidecar['ok'])
                    <x-badge variant="success">reachable</x-badge>
                    <x-badge variant="{{ $sidecar['ml'] === (bool) config('analysis.essentia_ml') ? 'neutral' : 'warning' }}">{{ $sidecar['ml'] ? 'ML models' : 'no ML models' }}</x-badge>
                @else
                    <x-badge variant="danger">{{ $sidecar['error'] }}</x-badge>
                @endif
            </div>
            @if($sidecar['ok'])
                <p class="mt-2 text-sm text-gray-500">
                    @if($sidecar['models']) Loaded: {{ implode(', ', $sidecar['models']) }}. @elseif($sidecar['ml']) No model loaded; mood, voice and genre are skipped. @else Light build: BPM, key and danceability only. @endif
                    @if($sidecar['ml'] !== (bool) config('analysis.essentia_ml')) The sidecar and <code>ANALYSIS_ESSENTIA_ML</code> disagree: files are analysed again once they match. @endif
                </p>
            @else
                <p class="mt-2 text-sm text-gray-500">Essentia waits for it; the other stages carry on. Files fail this stage and are retried up to {{ config('analysis.max_attempts') }} times.</p>
            @endif
        </x-card>
    @endif

    {{-- Problems --}}
    <x-card flush data-test="analysis-problems">
        <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Problems <span class="text-sm font-normal text-gray-500">· {{ count($problems) }}{{ count($problems) >= 200 ? '+' : '' }}</span></h2>
            <x-select wire:model.live="stage" aria-label="Filter by stage" data-test="problem-filter">
                <option value="">All stages</option>
                @foreach($progress['stages'] as $key => $row)
                    <option value="{{ $key }}">{{ ucfirst($key) }}</option>
                @endforeach
            </x-select>
        </div>

        @forelse($problems as $problem)
            @php($attempts = $problem['error']['attempts'] ?? 1)
            <div class="flex flex-wrap items-start gap-x-4 gap-y-2 px-8 py-3 border-b border-gray-50 dark:border-stone-800/50 last:border-0 text-sm" wire:key="problem-{{ $problem['file']->id }}-{{ $problem['stage'] }}" data-test="problem-{{ $problem['file']->id }}-{{ $problem['stage'] }}">
                <div class="min-w-0 flex-1 basis-64">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-badge variant="{{ $attempts >= config('analysis.max_attempts') ? 'danger' : 'warning' }}">{{ $problem['stage'] }}</x-badge>
                        <span class="truncate text-gray-900 dark:text-gray-100" title="{{ $problem['file']->path }}">{{ $problem['file']->path }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 break-words">{{ $problem['error']['message'] ?? '' }}</p>
                    <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-600">{{ $attempts }} of {{ config('analysis.max_attempts') }} tries{{ $attempts >= config('analysis.max_attempts') ? ', given up' : '' }}@if(!empty($problem['error']['at'])) · {{ \Illuminate\Support\Carbon::parse($problem['error']['at'])->diffForHumans() }}@endif</p>
                </div>
                @if($progress['enabled'])
                    <div class="flex gap-2">
                        <x-secondary-button size="sm" wire:click="retry({{ $problem['file']->id }}, '{{ $problem['stage'] }}')" data-test="retry-{{ $problem['file']->id }}-{{ $problem['stage'] }}">Retry</x-secondary-button>
                        <x-secondary-button size="sm" wire:click="analyse({{ $problem['file']->id }})">Analyse file</x-secondary-button>
                    </div>
                @endif
            </div>
        @empty
            <x-empty-state bare icon="circle-check" title="No problems" text="No stage has failed on any file." />
        @endforelse
    </x-card>
</div>
