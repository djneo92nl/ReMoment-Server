<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="{{ route('settings.index') }}"
                   class="flex items-center justify-center w-9 h-9 rounded-xl bg-gray-100 dark:bg-stone-800 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-stone-700 transition-colors flex-shrink-0">
                    <i class="fa-solid fa-arrow-left text-sm"></i>
                </a>
                <div>
                    <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Listeners</h1>
                    <p class="mt-1.5 text-gray-500 dark:text-gray-500">Real-time device state processes</p>
                </div>
            </div>

            @if($devices->where('can_start', true)->isNotEmpty())
                <form method="POST" action="{{ route('settings.listeners.start-all') }}">
                    @csrf
                    <button type="submit"
                            class="flex items-center gap-2 px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-2xl text-sm font-medium transition-colors">
                        <i class="fa-solid fa-play"></i>
                        <span class="hidden sm:inline">Start all ASE</span>
                    </button>
                </form>
            @endif
        </div>
    </x-slot>

    <div class="max-w-4xl space-y-6">

        <!-- Explainer -->
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-2xl px-6 py-4 text-sm text-blue-800 dark:text-blue-300">
            <i class="fa-solid fa-circle-info mr-2"></i>
            Listeners are long-running processes that stream real-time state from devices. Each device has its own listener.
            A listener heartbeat expires after <strong>10 seconds</strong> — if a listener crashes it will show as inactive shortly after.
        </div>

        <!-- Device listener list -->
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">

            @if($devices->isEmpty())
                <div class="flex flex-col items-center justify-center py-20 text-center px-8">
                    <i class="fa-solid fa-tv text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-500">No devices registered yet.</p>
                </div>
            @else
                <!-- Table header -->
                <div class="grid grid-cols-12 gap-4 px-8 py-4 border-b border-gray-100 dark:border-stone-800">
                    <div class="col-span-4 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Device</div>
                    <div class="col-span-3 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Driver</div>
                    <div class="col-span-3 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Status</div>
                    <div class="col-span-2"></div>
                </div>

                @foreach($devices as $row)
                    @php($device = $row['device'])
                    <div class="grid grid-cols-12 gap-4 items-center px-8 py-5 border-b border-gray-50 dark:border-stone-800/50 last:border-0 hover:bg-gray-50/50 dark:hover:bg-stone-800/20 transition-colors">

                        <!-- Device name -->
                        <div class="col-span-4">
                            <a href="{{ route('devices.show', $device) }}"
                               class="text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                {{ $device->device_name }}
                            </a>
                            <div class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">{{ $device->ip_address }}</div>
                        </div>

                        <!-- Driver -->
                        <div class="col-span-3">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium
                                {{ $device->device_driver_name === 'ASE'
                                    ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400'
                                    : 'bg-gray-100 dark:bg-stone-800 text-gray-600 dark:text-gray-400' }}">
                                {{ $device->device_driver_name ?: 'Unknown' }}
                            </span>
                        </div>

                        <!-- Status -->
                        <div class="col-span-3">
                            @if($row['listener_running'])
                                <div class="flex items-center gap-2">
                                    <span class="relative flex w-2.5 h-2.5">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full w-2.5 h-2.5 bg-emerald-500"></span>
                                    </span>
                                    <span class="text-sm font-medium text-emerald-600 dark:text-emerald-500">Active</span>
                                </div>
                            @elseif($row['can_start'])
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-gray-300 dark:bg-stone-700"></span>
                                    <span class="text-sm text-gray-400 dark:text-gray-600">Inactive</span>
                                </div>
                            @else
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-gray-200 dark:bg-stone-800"></span>
                                    <span class="text-sm text-gray-300 dark:text-gray-700">Not supported</span>
                                </div>
                            @endif
                        </div>

                        <!-- Action -->
                        <div class="col-span-2 flex justify-end">
                            @if($row['can_start'] && !$row['listener_running'])
                                <form method="POST" action="{{ route('settings.listeners.start', $device) }}">
                                    @csrf
                                    <button type="submit"
                                            class="flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 rounded-xl text-xs font-medium transition-colors">
                                        <i class="fa-solid fa-play text-xs"></i>
                                        Start
                                    </button>
                                </form>
                            @elseif($row['listener_running'])
                                <span class="text-xs text-gray-400 dark:text-gray-600 px-3 py-1.5">Running</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

        <!-- Info card -->
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
            <h2 class="text-base font-medium text-gray-900 dark:text-gray-100 mb-5">Start all listeners</h2>
            <p class="text-sm text-gray-500 dark:text-gray-500 mb-5">
                Run this command to start listeners for all ASE devices that don't already have one running. Safe to re-run — it skips already-active listeners.
            </p>
            <div class="bg-gray-900 dark:bg-stone-950 rounded-2xl px-5 py-4 font-mono text-sm text-gray-300">
                php artisan app:get-current-playing-media
            </div>
        </div>
    </div>
</x-app-layout>
