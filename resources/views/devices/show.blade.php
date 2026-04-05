<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-start gap-4">
                <a href="{{ route('devices.index') }}"
                   class="mt-1 flex items-center justify-center w-9 h-9 rounded-xl bg-gray-100 dark:bg-stone-800 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-stone-700 transition-colors flex-shrink-0">
                    <i class="fa-solid fa-arrow-left text-sm"></i>
                </a>
                <div>
                    <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">{{ $device->device_name }}</h1>
                    <p class="mt-1.5 text-gray-500 dark:text-gray-500">
                        {{ $device->device_brand_name }}
                        @if($device->device_product_type)
                            · {{ $device->device_product_type }}
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 flex-shrink-0">
                <a href="{{ route('devices.edit', $device) }}"
                   class="flex items-center gap-2 px-4 py-2 bg-gray-100 dark:bg-stone-800 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium hover:bg-gray-200 dark:hover:bg-stone-700 transition-colors">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <span class="hidden sm:inline">Edit</span>
                </a>
                <form method="POST" action="{{ route('devices.destroy', $device) }}"
                      onsubmit="return confirm('Remove {{ addslashes($device->device_name) }}? This cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="flex items-center gap-2 px-4 py-2 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 rounded-xl text-sm font-medium hover:bg-red-100 dark:hover:bg-red-900/40 transition-colors">
                        <i class="fa-solid fa-trash"></i>
                        <span class="hidden sm:inline">Remove</span>
                    </button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="grid gap-7 lg:grid-cols-3">

        <!-- Left: Now Playing / Controls -->
        <div class="lg:col-span-2">
            <livewire:nowplaying :device="$device" :key="'np-show-'.$device->id" />
        </div>

        <!-- Right: Device Info Panel -->
        <div class="space-y-6">

            <!-- Device Info Card -->
            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
                <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-5">Device Info</h2>

                <dl class="space-y-4 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">IP Address</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200 text-right font-mono">{{ $device->ip_address }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Brand</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200 text-right">{{ $device->device_brand_name ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Product</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200 text-right">{{ $device->device_product_type ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Driver</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200 text-right">{{ $device->device_driver_name ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">State</dt>
                        <dd class="text-right">
                            @php($state = $device->state)
                            @if($state === \App\Domain\Device\State::Playing)
                                <span class="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-500 font-medium">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>Playing
                                </span>
                            @elseif($state === \App\Domain\Device\State::Paused)
                                <span class="inline-flex items-center gap-1.5 text-amber-600 dark:text-amber-500 font-medium">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>Paused
                                </span>
                            @elseif($state === \App\Domain\Device\State::Standby)
                                <span class="inline-flex items-center gap-1.5 text-red-600 dark:text-red-500 font-medium">
                                    <span class="w-2 h-2 rounded-full bg-red-500"></span>Standby
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 text-gray-500 dark:text-gray-500 font-medium">
                                    <span class="w-2 h-2 rounded-full bg-gray-400"></span>Unreachable
                                </span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Last seen</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200 text-right">
                            {{ $device->last_seen ? $device->last_seen->diffForHumans() : 'Never' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Added</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200 text-right">
                            {{ $device->created_at->format('M j, Y') }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Metadata Card -->
            @if($device->meta->isNotEmpty())
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
                    <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-5">Metadata</h2>
                    <dl class="space-y-3 text-sm">
                        @foreach($device->meta as $meta)
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-500 font-mono">{{ $meta->key }}</dt>
                                <dd class="font-medium text-gray-800 dark:text-gray-200 text-right font-mono text-xs break-all">{{ $meta->value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif

            <!-- Driver Details Card -->
            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
                <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-5">Driver</h2>
                <p class="text-xs text-gray-400 dark:text-gray-600 font-mono break-all leading-relaxed">{{ $device->device_driver ?: '—' }}</p>
            </div>
        </div>
    </div>
</x-app-layout>
