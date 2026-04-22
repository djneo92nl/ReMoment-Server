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
                <form method="POST" action="{{ route('devices.standby', $device) }}">
                    @csrf
                    <button type="submit"
                            class="flex items-center gap-2 px-4 py-2 bg-gray-100 dark:bg-stone-800 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium hover:bg-gray-200 dark:hover:bg-stone-700 transition-colors">
                        <i class="fa-solid fa-power-off"></i>
                        <span class="hidden sm:inline">Standby</span>
                    </button>
                </form>
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

        <!-- Left: Now Playing / Controls + History -->
        <div class="lg:col-span-2 space-y-6">
            <livewire:device-card :device="$device" :standalone="true" :key="'dc-show-'.$device->id" />
            <livewire:device-history :device="$device" :key="'hist-'.$device->id" />
        </div>

        <!-- Right: Info Panel -->
        <div class="space-y-6">

            <livewire:device-cache-card :device="$device" :key="'cache-'.$device->id" />

            <!-- Stats Card -->
            @if($stats['total_plays'] > 0)
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
                    <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-5">Playback Stats</h2>
                    <dl class="space-y-4 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-500">Total plays</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-200 text-right">{{ number_format($stats['total_plays']) }}</dd>
                        </div>
                        @if($stats['total_seconds'] > 0)
                            @php
                                $hours = floor($stats['total_seconds'] / 3600);
                                $minutes = floor(($stats['total_seconds'] % 3600) / 60);
                            @endphp
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-500">Listening time</dt>
                                <dd class="font-medium text-gray-800 dark:text-gray-200 text-right">
                                    @if($hours > 0)
                                        {{ $hours }}h {{ $minutes }}m
                                    @else
                                        {{ $minutes }}m
                                    @endif
                                </dd>
                            </div>
                        @endif
                        @if($stats['top_artist'])
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-500">Top artist</dt>
                                <dd class="font-medium text-gray-800 dark:text-gray-200 text-right truncate max-w-32">
                                    <a href="{{ route('artists.show', $stats['top_artist']->id) }}" class="hover:underline">
                                        {{ $stats['top_artist']->name }}
                                    </a>
                                </dd>
                            </div>
                        @endif
                    </dl>
                    <div class="mt-5 pt-4 border-t border-gray-100 dark:border-stone-800">
                        <a href="{{ route('history.index', ['deviceId' => $device->id]) }}"
                           class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">
                            View full history &rarr;
                        </a>
                    </div>
                </div>
            @endif

            <!-- Capabilities Card -->
            @if(!empty($capabilities))
                @php
                    $capMap = [
                        'media_controls' => ['fa-play',        'Media Controls'],
                        'volume_control' => ['fa-volume-high', 'Volume Control'],
                        'source_control' => ['fa-input-pipe',  'Source Control'],
                        'standby'        => ['fa-power-off',   'Standby'],
                        'speaker_groups' => ['fa-speaker',     'Speaker Groups'],
                        'sound_modes'    => ['fa-sliders',     'Sound Modes'],
                    ];
                @endphp
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
                    <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-5">Capabilities</h2>
                    <div class="flex flex-wrap gap-2">
                        @foreach($capabilities as $cap)
                            @php [$icon, $label] = $capMap[$cap] ?? ['fa-circle-check', $cap]; @endphp
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-100 dark:bg-stone-800 text-gray-700 dark:text-gray-300 rounded-xl text-xs font-medium">
                                <i class="fa-solid {{ $icon }} text-gray-400 dark:text-gray-500"></i>
                                {{ $label }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Sources trigger -->
            @if(in_array('source_control', $capabilities))
                @php $ownCount = $sources->where('borrowed', false)->count(); @endphp
                <button x-data @click="$dispatch('open-modal', 'sources-{{ $device->id }}')"
                        class="w-full bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 px-8 py-5 flex items-center justify-between text-left hover:bg-gray-50 dark:hover:bg-stone-800/60 transition-colors">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-input-pipe text-gray-400 dark:text-gray-500"></i>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Sources</span>
                        @if($ownCount > 0)
                            <span class="text-xs text-gray-400 dark:text-gray-600">{{ $ownCount }}</span>
                        @endif
                    </div>
                    <i class="fa-solid fa-chevron-right text-gray-300 dark:text-stone-600 text-xs"></i>
                </button>

                <x-modal name="sources-{{ $device->id }}" maxWidth="md">
                    <div x-data="{ showBorrowed: false }" class="bg-white dark:bg-stone-900 rounded-2xl overflow-hidden">
                        <div class="flex items-center justify-between px-6 pt-6 pb-4 border-b border-gray-100 dark:border-stone-800">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Sources</h3>
                            <button type="button" @click="$dispatch('close-modal', 'sources-{{ $device->id }}')"
                                    class="flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 dark:text-gray-600 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                <i class="fa-solid fa-xmark text-xs"></i>
                            </button>
                        </div>

                        @if($sources->isEmpty())
                            <div class="px-6 py-8 text-center">
                                <p class="text-sm text-gray-400 dark:text-gray-600 mb-3">No sources synced yet.</p>
                                <p class="text-xs text-gray-400 dark:text-gray-600 font-mono">
                                    GET /api/devices/{{ $device->id }}/sources
                                </p>
                            </div>
                        @else
                            @php
                                $categoryIcon = [
                                    'MUSIC' => 'fa-music',
                                    'RADIO' => 'fa-radio',
                                    'MEDIA' => 'fa-photo-film',
                                ];
                                $borrowedCount = $sources->where('borrowed', true)->count();
                            @endphp
                            <ul class="px-3 py-3 space-y-0.5">
                                @foreach($sources as $source)
                                    <li @if($source->borrowed) x-show="showBorrowed" x-cloak @endif
                                        class="flex items-center gap-3 px-3 py-2 rounded-xl text-sm group">
                                        <i class="fa-solid {{ $categoryIcon[$source->category] ?? 'fa-circle' }} text-gray-400 dark:text-gray-500 w-4 text-center flex-shrink-0 text-xs"></i>
                                        <div class="flex-1 min-w-0">
                                            <span class="text-gray-800 dark:text-gray-200 truncate block">{{ $source->friendly_name }}</span>
                                            @if($source->borrowed)
                                                <span class="text-xs text-gray-400 dark:text-gray-600">↳ {{ $source->provider_name }}</span>
                                            @endif
                                        </div>
                                        <span class="text-xs text-gray-400 dark:text-gray-600 flex-shrink-0">{{ $source->category }}</span>
                                        @if(in_array('source_activation', $capabilities))
                                            <form method="POST"
                                                  action="{{ route('devices.sources.activate', [$device, $source]) }}"
                                                  class="flex-shrink-0">
                                                @csrf
                                                <button type="submit"
                                                        class="flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 dark:text-gray-600 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-700 dark:hover:text-gray-300 transition-colors opacity-0 group-hover:opacity-100">
                                                    <i class="fa-solid fa-play text-xs"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            @if($borrowedCount > 0)
                                <div class="px-6 py-4 border-t border-gray-100 dark:border-stone-800">
                                    <label class="flex items-center gap-2.5 cursor-pointer select-none">
                                        <input type="checkbox" x-model="showBorrowed"
                                               class="rounded border-gray-300 dark:border-stone-600 text-gray-600 dark:bg-stone-800 focus:ring-0">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">
                                            Show {{ $borrowedCount }} borrowed {{ Str::plural('source', $borrowedCount) }}
                                        </span>
                                    </label>
                                </div>
                            @endif
                        @endif
                    </div>
                </x-modal>
            @endif

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
                        <dt class="text-gray-500 dark:text-gray-500">UUID</dt>
                        <dd class="font-medium text-gray-600 dark:text-gray-400 text-right font-mono text-xs break-all">{{ $device->uuid }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Added</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200 text-right">
                            {{ $device->created_at->format('M j, Y') }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- MQTT Card -->
            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
                <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-5">MQTT</h2>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-500 mb-1.5">Topic prefix</dt>
                        <dd class="font-mono text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-stone-800 rounded-xl px-3 py-2 break-all">{{ $mqttTopic }}</dd>
                    </div>
                    <div class="pt-1 space-y-1.5">
                        @foreach(['state', 'now_playing', 'progress'] as $subtopic)
                            <div class="font-mono text-xs text-gray-400 dark:text-gray-600">{{ $mqttTopic }}/{{ $subtopic }}</div>
                        @endforeach
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
                                <dt class="text-gray-500 dark:text-gray-500 font-mono text-xs">{{ $meta->key }}</dt>
                                <dd class="font-medium text-gray-800 dark:text-gray-200 text-right font-mono text-xs break-all">{{ $meta->value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif

            <!-- Driver Details Card -->
            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
                <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-5">Driver Class</h2>
                <p class="text-xs text-gray-400 dark:text-gray-600 font-mono break-all leading-relaxed">{{ $device->device_driver ?: '—' }}</p>
            </div>

        </div>
    </div>
</x-app-layout>
