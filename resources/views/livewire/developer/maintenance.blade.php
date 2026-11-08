<div class="space-y-6" data-test="maintenance">
    @if($message)<x-alert type="info" data-test="maint-message">{{ $message }}</x-alert>@endif

    <x-card class="p-6 sm:p-8" data-test="proxy-usage">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">Transcode cache</h2>
                <p class="mt-1 text-sm text-gray-500 tabular-nums">{{ number_format($totalBytes / 1048576, 1) }} MB of {{ $limit ?: '∞' }} MB (<code>PROXIES_MAX_MB</code>)</p>
            </div>
            <div class="flex gap-2">
                <x-secondary-button type="button" size="md" wire:click="prune">Prune now</x-secondary-button>
                <x-danger-button type="button" size="md" wire:click="purgeAll" wire:confirm="Delete every transcode and test signal?">Delete all</x-danger-button>
            </div>
        </div>
        @forelse($usage as $row)
            <div class="mt-3 flex items-center justify-between border-t border-gray-100 dark:border-stone-800 pt-3 text-sm tabular-nums">
                <span class="text-gray-900 dark:text-gray-100">{{ $row['name'] }} <span class="text-gray-500">· {{ $row['count'] }} {{ $row['count'] === 1 ? 'file' : 'files' }} · {{ number_format($row['bytes'] / 1048576, 1) }} MB</span></span>
                <x-secondary-button type="button" size="sm" wire:click="purgeProfile('{{ $row['name'] }}')">Delete</x-secondary-button>
            </div>
        @empty
            <p class="mt-4 text-sm text-gray-400">No transcodes made yet.</p>
        @endforelse
    </x-card>

    <x-card class="p-6 sm:p-8 space-y-4">
        <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">Server</h2>
        <div class="flex flex-wrap items-center gap-2">
            <x-select wire:model="deviceId" size="sm"><option value="">Device…</option>@foreach($devices as $d)<option value="{{ $d->id }}">{{ $d->device_name }}</option>@endforeach</x-select>
            <x-secondary-button type="button" size="md" wire:click="clearDeviceCache">Clear this device's cache</x-secondary-button>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-secondary-button type="button" size="md" wire:click="checkAlerts">Run alerts:check</x-secondary-button>
            <x-secondary-button type="button" size="md" wire:click="testAlert">Raise a test alert</x-secondary-button>
            @if($testAlertActive)<x-secondary-button type="button" size="md" wire:click="resolveTestAlert">Resolve it</x-secondary-button>@endif
            <x-secondary-button type="button" size="md" wire:click="restartWorkers" wire:confirm="Restart the queue workers after their current job?">Restart queue workers</x-secondary-button>
        </div>
    </x-card>
</div>
