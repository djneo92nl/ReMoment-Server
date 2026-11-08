<div class="space-y-6" data-test="capability-probe">
    <x-card class="p-6 sm:p-8">
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Find the most a player can do: play a rung, listen, mark it <strong>OK</strong> or <strong>Broken</strong> (silence, noise, stutter, refused). The marks are kept for the device. Rungs above the original's sample rate or bit depth are converted up; that tests the player, not the music.</p>
        <div class="grid gap-6 sm:grid-cols-2">
            @include('livewire.developer.partials.track-search')
            <div>
                <label class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Player</label>
                <x-select wire:model.live="deviceId" class="mt-2 w-full" data-test="probe-device">
                    <option value="">Choose a device</option>
                    @foreach($devices as $d)<option value="{{ $d->id }}">{{ $d->device_name }}</option>@endforeach
                </x-select>
            </div>
        </div>
    </x-card>

    @if($deviceId && $summary)
        <x-card class="p-6 sm:p-8" data-test="probe-summary">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">What this player does</h2>
                <x-secondary-button type="button" size="sm" wire:click="clear">Forget</x-secondary-button>
            </div>
            <dl class="mt-4 space-y-3 text-sm">
                @foreach($summary as $group => $row)
                    <div>
                        <dt class="text-gray-500">{{ $group }}@if($row['max_rate']) · up to {{ $row['max_rate'] / 1000 }} kHz @endif</dt>
                        <dd class="text-gray-900 dark:text-gray-100">
                            @if($row['ok'])<span class="text-emerald-600">OK:</span> {{ implode(', ', $row['ok']) }}@endif
                            @if($row['broken'])<div><span class="text-red-500">Broken:</span> {{ implode(', ', $row['broken']) }}</div>@endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-card>
    @endif

    @if($track && $deviceId)
        <x-card class="p-6 sm:p-8">
            @foreach($rungs as $group => $items)
                <div class="pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">{{ $group }}</div>
                @foreach($items as $rung)
                    @php $verdict = $verdicts[$rung['name']] ?? null; @endphp
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 dark:border-stone-800 py-3 text-sm" data-test="probe-{{ $rung['name'] }}">
                        <div class="text-gray-900 dark:text-gray-100">{{ $rung['label'] }}
                            @if($verdict)<x-badge>{{ $verdict }}</x-badge>@endif
                        </div>
                        <div class="flex gap-2">
                            <x-primary-button type="button" size="sm" wire:click="play('{{ $rung['name'] }}')" wire:loading.attr="disabled">Play</x-primary-button>
                            <x-secondary-button type="button" size="sm" wire:click="mark('{{ $rung['name'] }}', 'ok')">OK</x-secondary-button>
                            <x-secondary-button type="button" size="sm" wire:click="mark('{{ $rung['name'] }}', 'broken')">Broken</x-secondary-button>
                        </div>
                    </div>
                @endforeach
            @endforeach
            <p wire:loading class="mt-4 text-sm text-gray-500"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Working…</p>
        </x-card>
    @endif

    @if($result)<x-alert :type="$result['ok'] ? 'info' : 'error'" data-test="probe-result">{{ $result['message'] }}</x-alert>@endif
</div>
