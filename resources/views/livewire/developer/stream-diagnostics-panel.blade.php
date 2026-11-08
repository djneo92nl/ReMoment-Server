<div class="space-y-6" data-test="stream-diagnostics" @if($logging) wire:poll.3s @endif>
    <x-card class="p-6 sm:p-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">Request log</h2>
                <p class="mt-1 text-sm text-gray-500">What speakers ask <code>/stream</code> for: address, user agent, range, answer. Off by itself after {{ $logMinutes }} minutes; the last 100 are kept. The time is until the answer starts, not the whole transfer.</p>
            </div>
            <div class="flex gap-2">
                <x-primary-button type="button" size="md" wire:click="toggleLog" data-test="log-toggle">{{ $logging ? 'Stop logging' : 'Start logging' }}</x-primary-button>
                <x-secondary-button type="button" size="md" wire:click="clearLog">Clear</x-secondary-button>
            </div>
        </div>

        @if($entries)
            <div class="mt-5 overflow-x-auto">
                <table class="w-full text-xs tabular-nums" data-test="log-table">
                    <thead class="text-left text-gray-400"><tr><th class="py-1 pr-3">Time</th><th class="pr-3">Track</th><th class="pr-3">From</th><th class="pr-3">Range</th><th class="pr-3">Profile</th><th class="pr-3">Status</th><th class="pr-3">Size</th><th class="pr-3">ms</th><th>User agent</th></tr></thead>
                    @foreach($entries as $e)
                        <tr class="border-t border-gray-100 dark:border-stone-800 align-top text-gray-700 dark:text-gray-300">
                            <td class="py-1 pr-3">{{ $e['at'] }}</td><td class="pr-3">{{ $e['track'] }}</td><td class="pr-3">{{ $e['ip'] }}</td>
                            <td class="pr-3">{{ $e['range'] ?? '–' }}</td><td class="pr-3">{{ $e['profile'] ?? '–' }}</td>
                            <td class="pr-3 {{ $e['status'] >= 400 ? 'text-red-500' : '' }}">{{ $e['status'] }}{{ $e['note'] ? ' '.$e['note'] : '' }}</td>
                            <td class="pr-3">{{ $e['bytes'] ? number_format($e['bytes'] / 1048576, 1).' MB' : '–' }}</td><td class="pr-3">{{ $e['ms'] }}</td>
                            <td class="max-w-xs truncate" title="{{ $e['agent'] }}">{{ $e['agent'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @elseif($logging)
            <p class="mt-5 text-sm text-gray-400">Waiting for a request…</p>
        @endif
    </x-card>

    <x-card class="p-6 sm:p-8" data-test="faults">
        <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">Inject faults</h2>
        <p class="mt-1 text-sm text-gray-500">Makes every (or a percentage of) <code>/stream</code> request slow or fail, to see how a speaker copes. Ends by itself after {{ $faultMinutes }} minutes. It affects every speaker, also while you are not looking.</p>

        @if($fault)
            <x-alert type="warning" class="mt-4" data-test="fault-active">
                Active: {{ $fault['mode'] === 'delay' ? 'a delay of '.$fault['delay_ms'].' ms' : 'HTTP '.$fault['mode'] }} on {{ $fault['percent'] }}% of the requests.
                <x-slot name="action"><x-danger-button type="button" size="sm" wire:click="stopFault">Stop</x-danger-button></x-slot>
            </x-alert>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <x-select wire:model="mode" size="sm">@foreach($modes as $m)<option value="{{ $m }}">{{ $m === 'delay' ? 'Delay' : 'HTTP '.$m }}</option>@endforeach</x-select>
            <x-text-input wire:model="percent" type="number" min="1" max="100" size="sm" class="w-20" title="Percent of requests" />
            <span class="text-sm text-gray-500">%</span>
            <x-text-input wire:model="delayMs" type="number" min="0" max="10000" step="500" size="sm" class="w-24" title="Delay in ms" />
            <span class="text-sm text-gray-500">ms (delay only)</span>
            <x-primary-button type="button" size="md" wire:click="injectFault" data-test="fault-start">Start</x-primary-button>
        </div>
    </x-card>
</div>
