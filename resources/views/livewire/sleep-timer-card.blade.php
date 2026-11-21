<div class="space-y-3" wire:poll.15s>
    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Sleep timer</h3>

    @if($feedback)
        <x-alert :type="$feedbackIsError ? 'error' : 'success'">{{ $feedback }}</x-alert>
    @endif

    <x-card compact class="p-6 space-y-4">
        <p class="text-sm text-gray-500">Fade the volume down, then stop (or switch to standby) after a while. The volume is put back afterwards. The server does this, so it works on any speaker you can stop.</p>

        <form wire:submit="start" class="grid gap-4 sm:grid-cols-5 items-end">
            <div class="sm:col-span-2">
                <x-input-label value="Device" class="mb-1.5" />
                <x-select wire:model="deviceId" class="w-full">
                    <option value="">Choose a device…</option>
                    @foreach($devices as $device)
                        <option value="{{ $device->id }}">{{ $device->device_name }}</option>
                    @endforeach
                </x-select>
            </div>
            <div>
                <x-input-label value="Minutes" class="mb-1.5" />
                <x-text-input wire:model="minutes" type="number" min="1" max="480" class="w-full" />
            </div>
            <div>
                <x-input-label value="Fade (seconds)" class="mb-1.5" />
                <x-text-input wire:model="fadeSeconds" type="number" min="0" max="600" class="w-full" />
            </div>
            <div>
                <x-input-label value="At the end" class="mb-1.5" />
                <x-select wire:model="end" class="w-full">
                    <option value="stop">Stop</option>
                    <option value="standby">Standby</option>
                </x-select>
            </div>
            <div class="sm:col-span-5">
                <x-primary-button size="md" type="submit" wire:loading.attr="disabled"><i class="fa-solid fa-bed text-xs"></i>Start sleep timer</x-primary-button>
            </div>
        </form>

        @foreach($active as $timer)
            <div class="flex items-center gap-3 rounded-xl bg-gray-100 dark:bg-stone-800 px-4 py-3" wire:key="sleep-{{ $timer->id }}">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $timer->device?->device_name }}</p>
                    <p class="text-xs text-gray-500 tabular-nums">
                        {{ ($timer->state['action'] ?? 'stop') === 'standby' ? 'Standby' : 'Stop' }} in {{ max(0, (int) ceil(now()->diffInSeconds($timer->ends_at, false) / 60)) }} min
                    </p>
                </div>
                <x-secondary-button size="sm" type="button" wire:click="cancel({{ $timer->device_id }})">Cancel</x-secondary-button>
            </div>
        @endforeach
    </x-card>
</div>
