<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Radio</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">{{ $stations->count() }} {{ Str::plural('station', $stations->count()) }} in your library</p>
            </div>
            <a href="{{ route('radio.create') }}"
               class="flex items-center gap-2 px-5 py-2.5 bg-gray-900 dark:bg-stone-700 text-white rounded-2xl text-sm font-medium hover:bg-gray-700 dark:hover:bg-stone-600 transition-colors">
                <i class="fa-solid fa-plus"></i>
                Add station
            </a>
        </div>
    </x-slot>

    @if($stations->isEmpty())
        <div class="bg-white dark:bg-stone-900 rounded-3xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-16 text-center">
            <i class="fa-solid fa-radio text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
            <p class="text-gray-500 dark:text-gray-500 font-medium mb-1">No radio stations yet</p>
            <p class="text-gray-400 dark:text-gray-600 text-sm mb-6">Add a station and set platform identifiers to play it on any supported device</p>
            <a href="{{ route('radio.create') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-900 dark:bg-stone-700 text-white rounded-2xl text-sm font-medium hover:bg-gray-700 dark:hover:bg-stone-600 transition-colors">
                <i class="fa-solid fa-plus"></i>Add station
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($stations as $station)
                @php
                    // Only offer devices whose driver has the matching identifier set
                    $playableDevices = $devices->filter(function ($device) use ($station) {
                        try {
                            return $device->driver->canPlayRadioStation($station);
                        } catch (\Throwable) {
                            return false;
                        }
                    });
                @endphp

                <div class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-5 flex flex-col gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 rounded-xl overflow-hidden flex-shrink-0 bg-gray-100 dark:bg-stone-800 flex items-center justify-center">
                            @if($station->image_url)
                                <img src="{{ $station->image_url }}" alt="{{ $station->name }}" class="w-full h-full object-cover">
                            @else
                                <i class="fa-solid fa-radio text-gray-300 dark:text-stone-600 text-xl"></i>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $station->name }}</p>
                            <div class="flex flex-wrap gap-1 mt-1">
                                @forelse($station->meta as $id)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-gray-100 dark:bg-stone-800 text-xs text-gray-500 dark:text-gray-400 font-mono">
                                        {{ $id->key }}
                                    </span>
                                @empty
                                    <span class="text-xs text-amber-500 dark:text-amber-400">
                                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>No identifiers
                                    </span>
                                @endforelse
                            </div>
                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">
                                {{ number_format($station->plays_count) }} {{ Str::plural('play', $station->plays_count) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-1 flex-shrink-0">
                            <a href="{{ route('radio.edit', $station) }}"
                               class="flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 dark:text-gray-600 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">
                                <i class="fa-solid fa-pen text-xs"></i>
                            </a>
                            <form method="POST" action="{{ route('radio.destroy', $station) }}"
                                  onsubmit="return confirm('Remove {{ addslashes($station->name) }}?')">
                                @csrf @method('DELETE')
                                <button type="submit"
                                        class="flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 dark:text-gray-600 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-500 transition-colors">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Play on device --}}
                    @if($playableDevices->isNotEmpty())
                        <button
                            type="button"
                            @click="$dispatch('open-modal', 'play-{{ $station->id }}')"
                            class="w-full flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-gray-50 dark:bg-stone-800 text-gray-600 dark:text-gray-400 text-sm font-medium hover:bg-gray-100 dark:hover:bg-stone-700 transition-colors"
                        >
                            <i class="fa-solid fa-play text-xs"></i>
                            Play on&hellip;
                        </button>
                        <x-device-picker
                            name="play-{{ $station->id }}"
                            title="Play on device"
                            :description="$station->name"
                            :devices="$playableDevices"
                            :action-template="url('radio/' . $station->id . '/play') . '/{id}'"
                        />
                    @else
                        <p class="text-xs text-center text-gray-400 dark:text-gray-600">
                            No compatible devices — add a platform identifier to enable playback
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-app-layout>
