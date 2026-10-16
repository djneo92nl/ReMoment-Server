<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Radio" :subtitle="$stations->count().' '.Str::plural('station', $stations->count()).' in your library'">
            <x-slot name="actions">
                <x-primary-button href="{{ route('radio.create') }}">
                    <i class="fa-solid fa-plus"></i>
                    Add station
                </x-primary-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @if($stations->isEmpty())
        <x-empty-state icon="radio" title="No radio stations yet" text="Add a station and set platform identifiers to play it on any supported device">
            <x-primary-button href="{{ route('radio.create') }}">
                <i class="fa-solid fa-plus"></i>Add station
            </x-primary-button>
        </x-empty-state>
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

                <x-card compact class="p-5 flex flex-col gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 rounded-xl overflow-hidden flex-shrink-0 bg-gray-100 dark:bg-stone-800 flex items-center justify-center">
                            @if($cover = $artwork[$station->id] ?? null)
                                <img src="{{ $cover }}" alt="{{ $station->name }}" class="w-full h-full object-cover">
                            @else
                                <i class="fa-solid fa-radio text-gray-300 dark:text-stone-600 text-xl"></i>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $station->name }}</p>
                            <div class="flex flex-wrap gap-1 mt-1">
                                @forelse($station->meta as $id)
                                    <x-badge class="font-mono">{{ $id->key }}</x-badge>
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
                            <form method="POST" action="{{ route('radio.favorite', $station) }}">
                                @csrf
                                <button type="submit"
                                        title="{{ $station->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}"
                                        class="flex items-center justify-center w-8 h-8 rounded-xl transition-colors {{ $station->favorited_at ? 'text-rose-500 hover:text-rose-600' : 'text-gray-300 hover:text-rose-400 dark:text-stone-600' }}">
                                    <i class="{{ $station->favorited_at ? 'fa-solid' : 'fa-regular' }} fa-heart text-sm"></i>
                                </button>
                            </form>
                            <a href="{{ route('radio.edit', $station) }}"
                               class="flex items-center justify-center w-8 h-8 rounded-xl text-gray-400 dark:text-gray-600 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">
                                <i class="fa-solid fa-pen text-xs"></i>
                            </a>
                            <form method="POST" action="{{ route('radio.destroy', $station) }}"
                                  onsubmit="return confirm('Remove {{ addslashes($station->name) }}?')">
                                @csrf @method('DELETE')
                                <button type="submit"
                                        class="flex items-center justify-center w-8 h-8 rounded-xl text-gray-400 dark:text-gray-600 hover:bg-red-50 dark:hover:bg-red-900/20 hover:text-red-500 transition-colors">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Play on device --}}
                    @if($playableDevices->isNotEmpty())
                        <x-play-button
                            name="play-{{ $station->id }}"
                            title="Play on device"
                            :description="$station->name"
                            :devices="$playableDevices"
                            :action-template="\App\Support\DeviceAction::template('radio.play', ['radio' => $station])"
                            wrapper-class="w-full"
                            class="flex-1 flex items-center justify-center gap-2 px-4 py-2 rounded-xl bg-gray-50 dark:bg-stone-800 text-gray-600 dark:text-gray-400 text-sm font-medium hover:bg-gray-100 dark:hover:bg-stone-700 transition-colors">
                            <i class="fa-solid fa-play text-xs"></i>
                            Play
                        </x-play-button>
                    @else
                        <p class="text-xs text-center text-gray-400 dark:text-gray-600">
                            No compatible devices — add a platform identifier to enable playback
                        </p>
                    @endif
                </x-card>
            @endforeach
        </div>
    @endif
</x-app-layout>
