<x-card class="p-6 sm:p-8 space-y-6" data-test="stream-tester">
    <p class="text-sm text-gray-500 dark:text-gray-400">Base address speakers get: <code class="text-gray-900 dark:text-gray-100" data-test="stream-base">{{ $base }}</code> (from {{ $baseFrom }}). The probe runs from the server, so a speaker on another network may still not reach it.</p>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-text-input wire:model="trackId" type="number" placeholder="Track id" data-test="stream-track" />
        <x-select wire:model="profile"><option value="">Original</option>@foreach($profiles as $name => $p)<option value="{{ $name }}">{{ $p['label'] ?? $name }}</option>@endforeach</x-select>
        <x-primary-button type="button" size="md" wire:click="probe" data-test="stream-probe">Probe</x-primary-button>
    </div>

    @foreach($probes as $probe)
        <div class="rounded-xl border border-gray-200 dark:border-stone-700 p-4 text-sm" data-test="probe">
            <div class="flex items-center justify-between gap-3">
                <span class="text-gray-900 dark:text-gray-100">{{ $probe['label'] }}</span>
                <x-badge>{{ $probe['status'] ?? 'failed' }}</x-badge>
            </div>
            <div class="mt-1 break-all font-mono text-xs text-gray-500">{{ $probe['url'] }}</div>
            @if($probe['error'])<div class="mt-2 text-xs text-red-500">{{ $probe['error'] }}</div>@endif
            @foreach($probe['headers'] as $h => $v)<div class="mt-1 font-mono text-xs text-gray-500">{{ $h }}: {{ $v }}</div>@endforeach
        </div>
    @endforeach
</x-card>
