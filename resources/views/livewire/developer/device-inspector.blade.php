<x-card class="p-6 sm:p-8 space-y-6" data-test="inspector" wire:poll.5s>
    <x-select wire:model.live="deviceId" data-test="inspect-device"><option value="">Choose a device</option>@foreach($devices as $d)<option value="{{ $d->id }}">{{ $d->device_name }}</option>@endforeach</x-select>

    @if($snapshot)
        <div class="flex flex-wrap gap-2">
            @foreach($calls as $call)
                <x-secondary-button type="button" size="sm" wire:click="call('{{ $call }}')">{{ str_replace('_', ' ', $call) }}</x-secondary-button>
            @endforeach
        </div>

        @if($result)<x-alert :type="$result['ok'] ? 'success' : 'error'" data-test="inspect-result">{{ $result['message'] }}</x-alert>@endif

        @if($timings)
            <div data-test="timings">
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Recent calls @if($averageMs)· average {{ $averageMs }} ms @endif</div>
                <table class="mt-2 w-full text-sm tabular-nums">
                    @foreach(array_slice($timings, 0, 10) as $t)
                        <tr class="border-b border-gray-100 dark:border-stone-800"><td class="py-1 text-gray-500">{{ $t['at'] }}</td><td class="text-gray-900 dark:text-gray-100">{{ $t['action'] }}</td><td class="text-right {{ $t['ok'] ? '' : 'text-red-500' }}">{{ $t['ok'] ? $t['ms'].' ms' : 'failed' }}</td></tr>
                    @endforeach
                </table>
            </div>
        @endif

        <pre class="max-h-[32rem] overflow-auto rounded-xl bg-gray-50 dark:bg-stone-800 p-4 text-xs text-gray-800 dark:text-gray-200" data-test="snapshot">{{ json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    @endif
</x-card>
