<div wire:poll.5s class="space-y-6" data-test="queue-manager">

    @if(session('health-success'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 6000)" x-show="show" x-transition
             style="position: fixed; right: 1.5rem; bottom: 1.5rem; z-index: 60; max-width: 24rem;"
             class="px-5 py-3 text-sm rounded-2xl shadow-xl border text-emerald-800 dark:text-emerald-200 bg-emerald-50 dark:bg-emerald-950 border-emerald-200 dark:border-emerald-800"
             data-test="health-toast">
            <i class="fa-solid fa-circle-check mr-2"></i>{{ session('health-success') }}
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2" data-test="queue-actions">
        <x-secondary-button size="sm" wire:click="queueMissingEnrichment" wire:loading.attr="disabled" data-test="queue-missing"><i class="fa-solid fa-wand-magic-sparkles mr-2"></i>Queue missing metadata</x-secondary-button>
        <x-secondary-button size="sm" wire:click="restartWorkers" wire:confirm="Restart the queue workers? They finish their current job first." data-test="restart-workers"><i class="fa-solid fa-rotate mr-2"></i>Restart workers</x-secondary-button>
    </div>

    {{-- Per queue: a runner that stopped shows as an oldest-waiting age that keeps growing --}}
    @if($queues)
        <x-card flush data-test="queue-overview">
            <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800">
                <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Queues</h2>
            </div>
            @foreach($queues as $queue)
                <div class="px-8 py-3 flex items-center gap-4 border-b border-gray-100 dark:border-stone-800 last:border-b-0" data-test="queue-{{ $queue['queue'] }}">
                    <div class="flex-1 text-sm text-gray-900 dark:text-gray-100">{{ $queue['queue'] }}</div>
                    <div class="text-xs text-gray-500">{{ $queue['waiting'] }} waiting · {{ $queue['running'] }} running · {{ $queue['delayed'] }} delayed</div>
                    <div class="w-36 text-xs text-gray-500 text-right">oldest waiting {{ $queue['oldest_waiting']?->diffForHumans(short: true) ?? '—' }}</div>
                </div>
            @endforeach
        </x-card>
    @endif

    {{-- Pending jobs --}}
    <x-card flush data-test="pending-jobs">
        <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800 flex items-center justify-between">
            <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Pending jobs</h2>
            @if($pendingJobs)<span class="text-sm text-gray-500">{{ number_format($pendingJobs['total']) }} in queue</span>@endif
        </div>

        @if($pendingJobs === null)
            <div class="px-8 py-8 text-center text-sm text-gray-500">The pending jobs can't be listed for this queue driver.</div>
        @else
            @forelse($pendingJobs['groups'] as $group)
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 px-8 py-3 border-b border-gray-50 dark:border-stone-800/50 last:border-0 text-sm">
                    <div class="flex-1 min-w-40 font-medium text-gray-900 dark:text-gray-100 truncate">{{ $group['job'] }}</div>
                    <div class="w-16 text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($group['count']) }}</div>
                    <div class="text-xs text-gray-500">{{ $group['waiting'] }} waiting · {{ $group['running'] }} running · {{ $group['delayed'] }} delayed</div>
                    <div class="w-28 text-xs text-gray-500 text-right">oldest {{ $group['oldest']?->diffForHumans(short: true) ?? '—' }}</div>
                </div>
            @empty
                <div class="px-8 py-8 text-center text-sm text-gray-500">No pending jobs.</div>
            @endforelse

            @if($pendingJobs['total'] > $pendingJobs['scanned'])
                <div class="px-8 py-3 text-xs text-gray-400 border-t border-gray-100 dark:border-stone-800">
                    Grouped from the first {{ number_format($pendingJobs['scanned']) }} of {{ number_format($pendingJobs['total']) }} jobs.
                </div>
            @endif
        @endif
    </x-card>

    {{-- Failed jobs --}}
    <x-card flush data-test="failed-jobs">
        <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">Failed jobs <span class="text-sm font-normal text-gray-500">· {{ number_format($failedCount) }}</span></h2>
            @if($failedCount > 0)
                <div class="flex gap-2">
                    <x-secondary-button size="sm" wire:click="retryAllFailed" data-test="retry-all">Retry all</x-secondary-button>
                    <x-secondary-button size="sm" wire:click="flushFailed" wire:confirm="Remove all failed jobs without retrying them?" data-test="flush-failed">Clear all</x-secondary-button>
                </div>
            @endif
        </div>

        @forelse($failedJobs as $failed)
            <div class="flex items-start gap-4 px-8 py-3 border-b border-gray-50 dark:border-stone-800/50 last:border-0 text-sm" wire:key="failed-{{ $failed['id'] }}" data-test="failed-{{ $failed['id'] }}">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline gap-x-3">
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $failed['job'] }}</span>
                        <span class="text-xs text-gray-500">{{ $failed['queue'] }} · {{ $failed['at']->diffForHumans() }}</span>
                    </div>
                    <div class="mt-0.5 text-xs text-red-600 dark:text-red-400 break-words" title="{{ $failed['message'] }}">{{ \Illuminate\Support\Str::limit($failed['message'], 160) }}</div>
                </div>
                <div class="flex gap-3 text-xs whitespace-nowrap pt-0.5">
                    <button wire:click="retryFailed('{{ $failed['id'] }}')" class="text-gray-900 dark:text-gray-100 underline">Retry</button>
                    <button wire:click="forgetFailed('{{ $failed['id'] }}')" class="text-gray-500 hover:underline">Forget</button>
                </div>
            </div>
        @empty
            <div class="px-8 py-8 text-center text-sm text-gray-500">No failed jobs.</div>
        @endforelse

        @if($failedCount > count($failedJobs))
            <div class="px-8 py-3 text-xs text-gray-400 border-t border-gray-100 dark:border-stone-800">Showing the latest {{ count($failedJobs) }}.</div>
        @endif
    </x-card>
</div>
