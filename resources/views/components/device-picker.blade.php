@props([
    'name',
    'title' => 'Choose a device',
    'description' => null,
    'devices',
    'actionTemplate',
    'method' => 'POST',
])

<x-modal :name="$name" maxWidth="sm">
    <div class="bg-white dark:bg-stone-900 rounded-lg overflow-hidden">
        {{-- Header --}}
        <div class="flex items-start justify-between px-6 pt-6 pb-4 border-b border-gray-100 dark:border-stone-800">
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

        {{-- Device list --}}
        <div class="px-3 py-3">
            @if($devices->isEmpty())
                <p class="text-sm text-center text-gray-400 dark:text-gray-600 py-6">
                    No compatible devices available.
                </p>
            @else
                <div class="space-y-0.5">
                    @foreach($devices as $device)
                        @php
                            $action = str_replace('{id}', $device->id, $actionTemplate);
                            $useMethod = strtoupper($method);
                            $formMethod = in_array($useMethod, ['GET', 'POST']) ? $useMethod : 'POST';
                            $state = $device->state;
                        @endphp
                        <form method="{{ $formMethod }}" action="{{ $action }}">
                            @csrf
                            @if($useMethod !== 'GET' && $useMethod !== 'POST')
                                @method($useMethod)
                            @endif
                            <button
                                type="submit"
                                class="w-full flex items-center gap-3 px-3 py-3 rounded-xl text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-stone-800 transition-colors text-left"
                            >
                                <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-stone-800 flex items-center justify-center flex-shrink-0">
                                    <i class="fa-solid fa-tv text-gray-400 dark:text-stone-500 text-xs"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $device->device_name }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-600 truncate">{{ $device->device_brand_name }} &middot; {{ $device->device_product_type }}</p>
                                </div>
                                @if($state === 'playing')
                                    <span class="text-xs font-medium text-emerald-500 flex-shrink-0">Playing</span>
                                @elseif($state === 'unreachable')
                                    <span class="text-xs font-medium text-red-400 flex-shrink-0">Unreachable</span>
                                @endif
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-modal>
