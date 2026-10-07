@props([
    'name',
    'title' => 'Choose a device',
    'description' => null,
    'devices',
    'actionTemplate',
    'method' => 'POST',
    // the device currently chosen (highlighted)
    'selectedId' => null,
])

<x-modal :name="$name" maxWidth="md">
    <div class="bg-white dark:bg-stone-900 rounded-lg overflow-hidden">
        {{-- Header --}}
        <div class="flex items-start justify-between px-6 pt-6 pb-4">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</h3>
                @if($description)
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ $description }}</p>
                @endif
            </div>
            <button
                type="button"
                @click="$dispatch('close-modal', '{{ $name }}')"
                class="ml-4 flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 dark:text-gray-600 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-600 dark:hover:text-gray-300 transition-colors flex-shrink-0"
            >
                <i class="fa-solid fa-xmark text-xs"></i>
            </button>
        </div>

        {{-- Players, each as a small player; a device in a multiroom session says so --}}
        <div class="px-4 pb-5" style="display:flex;flex-direction:column;gap:.6rem;max-height:70vh;overflow-y:auto">
            @if($devices->isEmpty())
                <p class="text-sm text-center text-gray-400 dark:text-gray-600 py-6">
                    No compatible devices available.
                </p>
            @else
                @foreach($devices as $device)
                    @php
                        $session = \App\Domain\Device\Cache\MultiRoom::get($device->id)?->resolve($device);
                        $tag = match ($session['role'] ?? null) {
                            'host' => $session['listeners'] ? 'Hosting +'.count($session['listeners']) : null,
                            'listener' => 'Joined '.($session['host']['name'] ?? ''),
                            default => null,
                        };
                    @endphp
                    <x-mini-player :device="$device" :tag="$tag" :select-method="$method" :volume-api="true" :modal="$name" :selected="$selectedId === $device->id"
                                   :select-url="str_replace('{id}', $device->id, $actionTemplate)" />
                @endforeach
            @endif
        </div>
    </div>
</x-modal>
