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
                <x-menu label="More actions" icon="ellipsis"
                        button="flex h-10 w-10 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 transition-colors hover:bg-gray-50 dark:border-stone-700 dark:bg-stone-800 dark:text-gray-300 dark:hover:bg-stone-700">
                    @if(array_intersect(auth()->check() ? \App\Livewire\DeviceSettings::SECTIONS : array_diff(\App\Livewire\DeviceSettings::SECTIONS, \App\Livewire\DeviceSettings::ADMIN_SECTIONS), $capabilities))
                        <x-menu.item icon="gear" onclick="window.location='{{ route('devices.settings', $device) }}'">Settings</x-menu.item>
                    @endif
                    <x-menu.item icon="pen-to-square" onclick="window.location='{{ route('devices.edit', $device) }}'">Edit</x-menu.item>
                    <form method="POST" action="{{ route('devices.toggle-hidden', $device) }}">
                        @csrf
                        <x-menu.item type="submit" :icon="$device->hidden ? 'eye' : 'eye-slash'">{{ $device->hidden ? 'Unhide' : 'Hide' }}</x-menu.item>
                    </form>
                    <form method="POST" action="{{ route('devices.destroy', $device) }}"
                          onsubmit="return confirm('Remove {{ addslashes($device->device_name) }}? This cannot be undone.')">
                        @csrf
                        @method('DELETE')
                        <x-menu.item type="submit" icon="trash" :danger="true" class="text-red-600 dark:text-red-400">Remove</x-menu.item>
                    </form>
                </x-menu>
            </x-slot>
        </x-page-header>
    </x-slot>

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
        $label = 'text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2';
    @endphp

    {{-- The one surface of this page is what is playing. Everything under it sits on the page: `flat` (app.css) drops the card look of the components inside, headings and hairlines separate the sections. --}}
    <div class="max-w-6xl mx-auto space-y-12">
        <livewire:device-card :device="$device" :standalone="true" :key="'dc-show-'.$device->id" />

        <div class="flat grid gap-x-16 gap-y-12 lg:grid-cols-5">

            <div class="lg:col-span-3 space-y-12 min-w-0">
                @if(rescue(fn () => $device->driver instanceof \App\Integrations\Philips\VideoPlayerDriver, false))
                    <livewire:jellyfin-shelf :device="$device" :key="'jellyfin-'.$device->id" />
                @endif
                @if(in_array('queue', $capabilities))
                    <livewire:device-queue :device="$device" :key="'queue-'.$device->id" />
                @endif
                @if(in_array('remote_control', $capabilities))
                    <livewire:remote-card :device="$device" :key="'remote-'.$device->id" />
                @endif
                @if(rescue(fn () => $device->driver instanceof \App\Integrations\Contracts\VideoDeviceInterface, false))
                    <livewire:tv-extras :device="$device" :key="'tv-extras-'.$device->id" />
                @endif
                <livewire:device-history :device="$device" :key="'hist-'.$device->id" />
            </div>

            <div class="lg:col-span-2 space-y-12 min-w-0">
                @if(in_array('source_control', $capabilities))
                    <livewire:device-source-manager :device="$device" :key="'srcs-'.$device->id" />
                @endif

                <section>
                    <h2 class="{{ $label }}">Details</h2>
                    <dl class="divide-y divide-gray-200 dark:divide-stone-800 text-sm [&>div]:py-3.5">
                        <x-detail-row label="IP Address" mono>{{ $device->ip_address }}</x-detail-row>
                        <x-detail-row label="Driver">{{ $device->device_driver_name ?: '—' }}</x-detail-row>
                        @if($stats['total_plays'] > 0)
                            <x-detail-row label="Total plays">{{ number_format($stats['total_plays']) }}</x-detail-row>
                        @endif
                        <x-detail-row label="Last seen">{{ $device->last_seen ? $device->last_seen->diffForHumans() : 'Never' }}</x-detail-row>
                        <x-detail-row label="MQTT topic" mono>{{ $mqttTopic }}</x-detail-row>
                    </dl>

                    <details class="mt-4 group">
                        <summary class="cursor-pointer select-none text-sm text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 transition-colors">More details</summary>
                        <div class="mt-6 space-y-8">
                            @if(!empty($capabilities))
                                <div>
                                    <h3 class="{{ $label }}">Capabilities</h3>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($capabilities as $cap)
                                            @php [$icon, $capLabel] = $capMap[$cap] ?? ['fa-circle-check', $cap]; @endphp
                                            <x-badge>
                                                <i class="fa-solid {{ $icon }} text-gray-400 dark:text-gray-500"></i>
                                                {{ $capLabel }}
                                            </x-badge>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div>
                                <h3 class="{{ $label }}">Device</h3>
                                <dl class="divide-y divide-gray-200 dark:divide-stone-800 text-sm [&>div]:py-3">
                                    <x-detail-row label="Brand">{{ $device->device_brand_name ?: '—' }}</x-detail-row>
                                    <x-detail-row label="Product">{{ $device->device_product_type ?: '—' }}</x-detail-row>
                                    <x-detail-row label="UUID" mono class="text-gray-600 dark:text-gray-400">{{ $device->uuid }}</x-detail-row>
                                    <x-detail-row label="Added">{{ $device->created_at->format('M j, Y') }}</x-detail-row>
                                    @foreach($device->meta as $meta)
                                        <x-detail-row :label="$meta->key" mono>{{ $meta->value }}</x-detail-row>
                                    @endforeach
                                </dl>
                                <p class="mt-3 text-xs text-gray-500 font-mono break-all leading-relaxed">{{ $device->device_driver ?: '—' }}</p>
                            </div>

                            <livewire:device-cache-card :device="$device" :key="'cache-'.$device->id" />
                        </div>
                    </details>

                    @if($stats['total_plays'] > 0)
                        <p class="mt-6"><a href="{{ route('history.index', ['deviceId' => $device->id]) }}" class="text-sm text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 transition-colors">View full history &rarr;</a></p>
                    @endif
                </section>
            </div>

        </div>
    </div>
</x-app-layout>
