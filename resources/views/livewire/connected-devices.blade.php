<x-card tag="section" class="p-8" title="Connected devices">
    <p class="text-sm text-gray-500">
        A device that is plugged into or borrowed by another one: a Chromecast on a TV's HDMI input, a BeoLink Converter a player borrows.
        Both keep working on their own; the link lets the host show and control the other while its input is selected.
    </p>

    <x-alert type="error" class="mt-4">{{ $error }}</x-alert>
    <x-alert type="success" class="mt-4">{{ $notice }}</x-alert>

    <div class="mt-5 space-y-5">
        <div>
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-2">Connected to {{ $device->device_name }}</div>
            @forelse($subLinks as $row)
                @php($link = $row['link'])
                <div class="flex items-center gap-3 py-2 text-sm" data-test="sub-link-{{ $link->id }}">
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('devices.show', $link->device) }}" class="text-gray-900 dark:text-gray-100 hover:underline">{{ $link->device->device_name }}</a>
                        <span class="text-gray-500">· {{ $link->kind }}@if($link->via !== '') · {{ $link->via }}@endif</span>
                    </div>
                    @if($row['active'] === true)<x-badge variant="success">Active</x-badge>
                    @elseif($row['active'] === null)<x-badge variant="outline">State unknown</x-badge>@endif
                    <x-danger-button size="sm" type="button" wire:click="remove({{ $link->id }})" wire:confirm="Remove this link? Both devices keep working on their own.">Remove</x-danger-button>
                </div>
            @empty
                <div class="text-sm text-gray-400 dark:text-gray-600">No device is connected to this one.</div>
            @endforelse
        </div>

        <div>
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-2">{{ $device->device_name }} is connected to</div>
            @forelse($hostLinks as $row)
                @php($link = $row['link'])
                <div class="flex items-center gap-3 py-2 text-sm" data-test="host-link-{{ $link->id }}">
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('devices.show', $link->host) }}" class="text-gray-900 dark:text-gray-100 hover:underline">{{ $link->host->device_name }}</a>
                        <span class="text-gray-500">· {{ $link->kind }}@if($link->via !== '') · {{ $link->via }}@endif</span>
                    </div>
                    @if($row['active'] === true)<x-badge variant="success">Active</x-badge>
                    @elseif($row['active'] === null)<x-badge variant="outline">State unknown</x-badge>@endif
                    <x-danger-button size="sm" type="button" wire:click="remove({{ $link->id }})" wire:confirm="Remove this link? Both devices keep working on their own.">Remove</x-danger-button>
                </div>
            @empty
                <div class="text-sm text-gray-400 dark:text-gray-600">This device is not connected to a host.</div>
            @endforelse
        </div>
    </div>

    @if($suggestions)
        <div class="mt-5 space-y-2" data-test="link-suggestions">
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Reported by {{ $device->device_name }}</div>
            @foreach($suggestions as $index => $suggestion)
                <div class="flex items-center gap-3 text-sm">
                    <div class="min-w-0 flex-1 text-gray-700 dark:text-gray-300">{{ $suggestion['device_name'] }} <span class="text-gray-500">· {{ $suggestion['kind'] }}@if($suggestion['borrowing']) · borrowed @endif</span></div>
                    <x-secondary-button size="sm" type="button" wire:click="accept({{ $index }})">Connect</x-secondary-button>
                </div>
            @endforeach
        </div>
    @endif

    <form wire:submit="add" class="mt-6 pt-6 border-t border-gray-100 dark:border-stone-800 space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="link-direction" value="This device" />
                <x-select id="link-direction" wire:model="direction" class="w-full">
                    <option value="sub">is the host of</option>
                    <option value="host">is connected to</option>
                </x-select>
            </div>
            <div>
                <x-input-label for="link-other" value="Device" />
                <x-select id="link-other" wire:model="otherId" class="w-full">
                    <option value="">Choose a device</option>
                    @foreach($others as $other)
                        <option value="{{ $other->id }}">{{ $other->device_name }}</option>
                    @endforeach
                </x-select>
                <x-input-error :messages="$errors->get('otherId')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="link-kind" value="Kind" />
                <x-select id="link-kind" wire:model="kind" class="w-full">
                    <option value="input">input (an HDMI input)</option>
                    <option value="source">source (a source of the host)</option>
                    <option value="borrow">borrow (a source the host borrowed)</option>
                </x-select>
            </div>
            <div>
                <x-input-label for="link-via" value="Input or source on the host" />
                <x-text-input id="link-via" type="text" wire:model="via" placeholder="hdmi_1" class="w-full" />
                <x-input-error :messages="$errors->get('via')" class="mt-1" />
            </div>
        </div>

        <div class="flex flex-wrap justify-end gap-3">
            @if($canSuggest)
                <x-secondary-button type="button" wire:click="findSuggestions" wire:loading.attr="disabled" wire:target="findSuggestions">
                    <i class="fa-solid fa-magnifying-glass"></i>Ask the device
                </x-secondary-button>
            @endif
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="add">Connect</x-primary-button>
        </div>
    </form>
</x-card>
