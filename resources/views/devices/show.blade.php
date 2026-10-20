<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$device->device_name"
                       :subtitle="$device->device_brand_name.($device->device_product_type ? ' · '.$device->device_product_type : '')"
                       :back="route('devices.index')">
            <x-slot name="actions">
                <form method="POST" action="{{ route('devices.standby', $device) }}">
                    @csrf
                    <x-secondary-button size="md" type="submit">
                        <i class="fa-solid fa-power-off"></i>
                        <span class="hidden sm:inline">Standby</span>
                    </x-secondary-button>
                </form>
                <form method="POST" action="{{ route('devices.toggle-hidden', $device) }}">
                    @csrf
                    <x-secondary-button size="md" type="submit">
                        <i class="fa-solid {{ $device->hidden ? 'fa-eye' : 'fa-eye-slash' }}"></i>
                        <span class="hidden sm:inline">{{ $device->hidden ? 'Unhide' : 'Hide' }}</span>
                    </x-secondary-button>
                </form>
                @if(array_intersect(auth()->check() ? \App\Livewire\DeviceSettings::SECTIONS : array_diff(\App\Livewire\DeviceSettings::SECTIONS, \App\Livewire\DeviceSettings::ADMIN_SECTIONS), $capabilities))
                    <x-secondary-button size="md" href="{{ route('devices.settings', $device) }}">
                        <i class="fa-solid fa-gear"></i>
                        <span class="hidden sm:inline">Settings</span>
                    </x-secondary-button>
                @endif
                <x-secondary-button size="md" href="{{ route('devices.edit', $device) }}">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <span class="hidden sm:inline">Edit</span>
                </x-secondary-button>
                <form method="POST" action="{{ route('devices.destroy', $device) }}"
                      onsubmit="return confirm('Remove {{ addslashes($device->device_name) }}? This cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <x-danger-button>
                        <i class="fa-solid fa-trash"></i>
                        <span class="hidden sm:inline">Remove</span>
                    </x-danger-button>
                </form>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">

        <!-- Left: Now Playing / Controls + History -->
        <div class="lg:col-span-2 space-y-6">
            <livewire:device-card :device="$device" :standalone="true" :key="'dc-show-'.$device->id" />
            @if(in_array('queue', $capabilities))
                <livewire:device-queue :device="$device" :key="'queue-'.$device->id" />
            @endif
            @if(in_array('source_control', $capabilities))
                <livewire:device-source-manager :device="$device" :key="'srcs-'.$device->id" />
            @endif
            <livewire:device-history :device="$device" :key="'hist-'.$device->id" />
        </div>

        <!-- Right: Info Panel -->
        <div class="space-y-6">

            <livewire:device-cache-card :device="$device" :key="'cache-'.$device->id" />

            @if($stats['total_plays'] > 0)
                <x-card title="Playback Stats" class="p-8">
                    <dl class="space-y-4 text-sm">
                        <x-detail-row label="Total plays">{{ number_format($stats['total_plays']) }}</x-detail-row>
                        @if($stats['total_seconds'] > 0)
                            <x-detail-row label="Listening time">{{ \App\Domain\Helpers\TimeHelper::humanDuration((int) $stats['total_seconds']) }}</x-detail-row>
                        @endif
                        @if($stats['top_artist'])
                            <x-detail-row label="Top artist" class="truncate max-w-32">
                                <a href="{{ route('artists.show', $stats['top_artist']->id) }}" class="hover:underline">{{ $stats['top_artist']->name }}</a>
                            </x-detail-row>
                        @endif
                    </dl>
                    <div class="mt-5 pt-4 border-t border-gray-100 dark:border-stone-800">
                        <a href="{{ route('history.index', ['deviceId' => $device->id]) }}"
                           class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">
                            View full history &rarr;
                        </a>
                    </div>
                </x-card>
            @endif

            @if(!empty($capabilities))
                @php
                    $capMap = [
                        'media_controls' => ['fa-play',        'Media Controls'],
                        'volume_control' => ['fa-volume-high', 'Volume Control'],
                        'source_control' => ['fa-input-pipe',  'Source Control'],
                        'standby'        => ['fa-power-off',   'Standby'],
                        'speaker_groups' => ['fa-speaker',     'Speaker Groups'],
                        'sound_modes'    => ['fa-sliders',     'Sound Modes'],
                        'multi_room'     => ['fa-layer-group', 'Multiroom'],
                        'radio_control'  => ['fa-radio',       'Radio'],
                        'source_activation' => ['fa-right-to-bracket', 'Source Activation'],
                        'library_playback' => ['fa-compact-disc', 'Library Playback'],
                        'seek'           => ['fa-forward',     'Seek'],
                        'queue'          => ['fa-list-ol',     'Up Next'],
                        'sound_adjustment' => ['fa-sliders',   'Sound Adjustment'],
                        'bluetooth'      => ['fa-link',       'Bluetooth'],
                        'device_info'    => ['fa-circle-info', 'Device Info'],
                    ];
                @endphp
                <x-card title="Capabilities" class="p-8">
                    <div class="flex flex-wrap gap-2">
                        @foreach($capabilities as $cap)
                            @php [$icon, $label] = $capMap[$cap] ?? ['fa-circle-check', $cap]; @endphp
                            <x-badge>
                                <i class="fa-solid {{ $icon }} text-gray-400 dark:text-gray-500"></i>
                                {{ $label }}
                            </x-badge>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <x-card title="Device Info" class="p-8">
                <dl class="space-y-4 text-sm">
                    <x-detail-row label="IP Address" mono>{{ $device->ip_address }}</x-detail-row>
                    <x-detail-row label="Brand">{{ $device->device_brand_name ?: '—' }}</x-detail-row>
                    <x-detail-row label="Product">{{ $device->device_product_type ?: '—' }}</x-detail-row>
                    <x-detail-row label="Driver">{{ $device->device_driver_name ?: '—' }}</x-detail-row>
                    <x-detail-row label="UUID" mono class="text-gray-600 dark:text-gray-400">{{ $device->uuid }}</x-detail-row>
                    <x-detail-row label="Added">{{ $device->created_at->format('M j, Y') }}</x-detail-row>
                </dl>
            </x-card>

            <x-card title="MQTT" class="p-8">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-gray-500 mb-1.5">Topic prefix</dt>
                        <dd class="font-mono text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-stone-800 rounded-xl px-3 py-2 break-all">{{ $mqttTopic }}</dd>
                    </div>
                    <div class="pt-1 space-y-1.5">
                        @foreach(['state', 'now_playing', 'progress'] as $subtopic)
                            <div class="font-mono text-xs text-gray-400 dark:text-gray-600">{{ $mqttTopic }}/{{ $subtopic }}</div>
                        @endforeach
                    </div>
                </dl>
            </x-card>

            @if($device->meta->isNotEmpty())
                <x-card title="Metadata" class="p-8">
                    <dl class="space-y-3 text-sm">
                        @foreach($device->meta as $meta)
                            <x-detail-row :label="$meta->key" mono>{{ $meta->value }}</x-detail-row>
                        @endforeach
                    </dl>
                </x-card>
            @endif

            <x-card title="Driver Class" class="p-8">
                <p class="text-xs text-gray-400 dark:text-gray-600 font-mono break-all leading-relaxed">{{ $device->device_driver ?: '—' }}</p>
            </x-card>

        </div>
    </div>
</x-app-layout>
