@php
    $card = 'bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8';
    $heading = 'text-base font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-5';
    $muted = 'text-sm text-gray-500 dark:text-gray-500';
    $row = 'flex items-center justify-between gap-4 py-3';
    $skeleton = '<div class="space-y-3"><div class="h-10 bg-gray-100 dark:bg-stone-800 rounded-xl animate-pulse"></div><div class="h-10 bg-gray-100 dark:bg-stone-800 rounded-xl animate-pulse"></div></div>';
@endphp

<div wire:init="load" class="space-y-6">
    @if(empty($capabilities))
        <div class="{{ $card }}">
            <p class="{{ $muted }}">This device has no settings ReMoment can change.</p>
        </div>
    @endif

    {{-- Device info --}}
    @if(in_array('device_info', $capabilities))
        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">Device</h2>
            @include('livewire.partials.settings-messages', ['section' => 'device_info', 'problems' => $problems, 'notices' => $notices])
            @if(!$ready)
                {!! $skeleton !!}
            @elseif($info)
                @if($info['renamable'])
                    <form wire:submit="rename" class="flex items-center gap-3">
                        <input type="text" wire:model="name" maxlength="64" aria-label="Device name"
                               class="flex-1 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 text-gray-700 dark:text-gray-300 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600">
                        <button type="submit" wire:loading.attr="disabled" wire:target="rename"
                                class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl text-sm font-medium disabled:opacity-50">Rename</button>
                    </form>
                @else
                    <div class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $info['name'] }}</div>
                @endif
                <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    @foreach(['product_type' => 'Model', 'firmware' => 'Firmware', 'mac_address' => 'MAC address'] as $key => $label)
                        @if($info[$key])
                            <dt class="{{ $muted }}">{{ $label }}</dt>
                            <dd class="text-gray-800 dark:text-gray-200 break-all">{{ $info[$key] }}</dd>
                        @endif
                    @endforeach
                </dl>
            @endif
        </section>
    @endif

    {{-- Sound adjustment --}}
    @if(in_array('sound_adjustment', $capabilities))
        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">Sound</h2>
            @include('livewire.partials.settings-messages', ['section' => 'sound_adjustment', 'problems' => $problems, 'notices' => $notices])
            @if(!$ready)
                {!! $skeleton !!}
            @elseif($sound)
                <div class="divide-y divide-gray-100 dark:divide-stone-800">
                    @foreach(['bass' => 'Bass', 'treble' => 'Treble'] as $part => $label)
                        @if($sound[$part])
                            <div class="{{ $row }}" x-data="{ value: {{ $sound[$part]['value'] }} }">
                                <label for="sound-{{ $part }}" class="text-sm text-gray-700 dark:text-gray-300 w-16">{{ $label }}</label>
                                <input id="sound-{{ $part }}" type="range" x-model.number="value"
                                       min="{{ $sound[$part]['min'] }}" max="{{ $sound[$part]['max'] }}" step="{{ $sound[$part]['step'] }}"
                                       x-on:change="$wire.setSound('{{ $part }}', value)"
                                       class="flex-1 accent-gray-700 dark:accent-stone-600">
                                <span class="w-8 text-right text-sm text-gray-800 dark:text-gray-200 tabular-nums" x-text="value > 0 ? '+' + value : value"></span>
                            </div>
                        @endif
                    @endforeach
                    @if($sound['loudness'] !== null)
                        <div class="{{ $row }}">
                            <span class="text-sm text-gray-700 dark:text-gray-300">Loudness</span>
                            <x-toggle :on="$sound['loudness']" label="Loudness" wire:click="setLoudness({{ $sound['loudness'] ? 'false' : 'true' }})" />
                        </div>
                    @endif
                </div>
            @endif
        </section>
    @endif

    {{-- Bluetooth --}}
    @if(in_array('bluetooth', $capabilities))
        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">Bluetooth</h2>
            @include('livewire.partials.settings-messages', ['section' => 'bluetooth', 'problems' => $problems, 'notices' => $notices])
            @if(!$ready)
                {!! $skeleton !!}
            @elseif($bluetooth)
                @if(!$bluetooth['enabled'])
                    <p class="{{ $muted }} mb-3">Bluetooth is switched off on this device.</p>
                @endif
                <div class="divide-y divide-gray-100 dark:divide-stone-800">
                    @if($bluetooth['writable'])
                        <div class="{{ $row }}">
                            <div>
                                <div class="text-sm text-gray-700 dark:text-gray-300">Discoverable</div>
                                <div class="text-xs text-gray-400 dark:text-gray-600">Turn on to pair a new phone, then pair from the phone.</div>
                            </div>
                            <x-toggle :on="$bluetooth['discoverable']" label="Discoverable" wire:click="setDiscoverable({{ $bluetooth['discoverable'] ? 'false' : 'true' }})" />
                        </div>
                        @if(!empty($bluetooth['reconnect_modes']))
                            <div class="{{ $row }}">
                                <label for="bt-reconnect" class="text-sm text-gray-700 dark:text-gray-300">Reconnect</label>
                                <x-select id="bt-reconnect" wire:change="setReconnectMode($event.target.value)">
                                    @foreach($bluetooth['reconnect_modes'] as $mode)
                                        <option value="{{ $mode }}" @selected($mode === $bluetooth['reconnect_mode'])>{{ ucfirst($mode) }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                        @endif
                    @endif
                </div>

                <h3 class="mt-6 mb-2 text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-600">Paired devices</h3>
                @forelse($bluetooth['devices'] as $paired)
                    <div class="{{ $row }}" wire:key="bt-{{ $paired['id'] }}">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ $paired['name'] }}</div>
                            <div class="text-xs text-gray-400 dark:text-gray-600">{{ $paired['connected'] ? 'Connected' : 'Not connected' }}@if($paired['address']) · {{ $paired['address'] }}@endif</div>
                        </div>
                        @if($bluetooth['writable'])
                            <button type="button" wire:click="removeBluetoothDevice(@js($paired['id']))"
                                    wire:confirm="Forget {{ $paired['name'] }}?"
                                    class="text-xs text-gray-400 hover:text-red-500 dark:text-gray-600 dark:hover:text-red-400 transition-colors">
                                <i class="fa-solid fa-trash"></i> Remove
                            </button>
                        @endif
                    </div>
                @empty
                    <p class="{{ $muted }}">No devices paired.</p>
                @endforelse
            @endif
        </section>
    @endif
    {{-- Network --}}
    @if(in_array('network_settings', $capabilities))
        <section class="{{ $card }}">
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">Network</h2>
                <button type="button" wire:click="reload('network_settings')" class="text-xs text-gray-400 hover:text-gray-600 dark:text-gray-600 dark:hover:text-gray-400">
                    <i class="fa-solid fa-rotate"></i> Refresh
                </button>
            </div>
            @include('livewire.partials.settings-messages', ['section' => 'network_settings', 'problems' => $problems, 'notices' => $notices])
            @if(!$ready)
                {!! $skeleton !!}
            @elseif($network)
                <div class="{{ $row }}">
                    <label for="net-interface" class="text-sm text-gray-700 dark:text-gray-300">Connected through</label>
                    <x-select id="net-interface"
                              x-on:change="confirm('Switch the network interface? The device can come back on a different address.') ? $wire.setInterface($event.target.value) : $event.target.value = '{{ $network['active_interface'] }}'">
                        @foreach($network['interfaces'] as $interface)
                            <option value="{{ $interface }}" @selected($interface === $network['active_interface'])>{{ $interface === 'wired' ? 'Wired (Ethernet)' : 'Wi-Fi' }}</option>
                        @endforeach
                    </x-select>
                </div>
                <p class="{{ $muted }}">Internet: {{ $network['internet_reachable'] ? 'reachable' : 'not reachable' }}</p>

                @foreach(['wired' => 'Wired', 'wireless' => 'Wi-Fi'] as $type => $label)
                    @if($network[$type])
                        @php($c = $network[$type])
                        <h3 class="mt-6 mb-2 text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-600">{{ $label }} · {{ $c['status'] }}</h3>
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                            @foreach(['ssid' => 'Network', 'frequency' => 'Band', 'quality' => 'Signal', 'address' => 'Address', 'subnet_mask' => 'Subnet mask', 'gateway' => 'Gateway', 'preferred_dns' => 'DNS', 'alternative_dns' => 'DNS (alt.)'] as $key => $name)
                                @if($c[$key])
                                    <dt class="{{ $muted }}">{{ $name }}</dt>
                                    <dd class="text-gray-800 dark:text-gray-200 break-all">{{ $c[$key] }}@if($key === 'quality' && $c['signal']) ({{ $c['signal'] }} dBm)@endif</dd>
                                @endif
                            @endforeach
                            @if($c['dhcp'] !== null)
                                <dt class="{{ $muted }}">Address from</dt>
                                <dd class="text-gray-800 dark:text-gray-200">{{ $c['dhcp'] ? 'DHCP' : 'Static' }}</dd>
                            @endif
                        </dl>
                    @endif
                @endforeach

                @if($network['wired'])
                    <h3 class="mt-6 mb-2 text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-600">Wired address</h3>
                    <form wire:submit="saveWired" class="space-y-3">
                        <div class="{{ $row }}">
                            <label for="wired-mode" class="text-sm text-gray-700 dark:text-gray-300">Get address from</label>
                            <x-select id="wired-mode" wire:model.live="wiredDhcp">
                                <option value="1">DHCP</option>
                                <option value="0">Static address</option>
                            </x-select>
                        </div>
                        @unless($wiredDhcp)
                            @foreach(['address' => 'IP address', 'subnet_mask' => 'Subnet mask', 'gateway' => 'Gateway', 'preferred_dns' => 'DNS', 'alternative_dns' => 'DNS (alt.)'] as $key => $name)
                                <div class="flex items-center gap-3">
                                    <label for="wired-{{ $key }}" class="w-28 text-sm text-gray-700 dark:text-gray-300">{{ $name }}</label>
                                    <input id="wired-{{ $key }}" type="text" wire:model="wired.{{ $key }}" inputmode="decimal" autocomplete="off"
                                           class="flex-1 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 text-gray-700 dark:text-gray-300 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600">
                                </div>
                            @endforeach
                        @endunless
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveWired"
                                onclick="return confirm('Change the wired address? The device can come back on a different address.')"
                                class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl text-sm font-medium disabled:opacity-50">Apply</button>
                    </form>
                @endif

                @if($network['wireless'])
                    <h3 class="mt-6 mb-2 text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-600">Join a Wi-Fi network</h3>
                    @forelse($network['wifi_networks'] as $known)
                        <div class="{{ $row }}" wire:key="wifi-{{ $known['ssid'] }}">
                            <span class="text-sm text-gray-800 dark:text-gray-200">{{ $known['ssid'] }}</span>
                            <span class="text-xs text-gray-400 dark:text-gray-600">{{ $known['active'] ? 'Connected' : 'Known' }}@if($known['frequency']) · {{ $known['frequency'] }}@endif</span>
                        </div>
                    @empty
                        <p class="{{ $muted }} mb-2">No Wi-Fi network is set up.</p>
                    @endforelse
                    <p class="{{ $muted }} mb-3">The device cannot scan for networks: type the name.</p>
                    <form wire:submit="joinWifi" class="space-y-3">
                        <div class="flex items-center gap-3">
                            <label for="wifi-ssid" class="w-28 text-sm text-gray-700 dark:text-gray-300">Network name</label>
                            <input id="wifi-ssid" type="text" wire:model="wifiSsid" maxlength="32" autocomplete="off" class="flex-1 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 text-gray-700 dark:text-gray-300 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600">
                        </div>
                        <div class="flex items-center gap-3">
                            <label for="wifi-pass" class="w-28 text-sm text-gray-700 dark:text-gray-300">Passphrase</label>
                            <input id="wifi-pass" type="password" wire:model="wifiPassphrase" maxlength="63" autocomplete="new-password" class="flex-1 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 text-gray-700 dark:text-gray-300 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600">
                        </div>
                        <div class="flex items-center gap-3">
                            <label for="wifi-security" class="w-28 text-sm text-gray-700 dark:text-gray-300">Security</label>
                            <input id="wifi-security" type="text" wire:model="wifiSecurity" maxlength="32" placeholder="optional, e.g. wpa2PskTkip" autocomplete="off" class="flex-1 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 text-gray-700 dark:text-gray-300 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600">
                        </div>
                        <button type="submit" wire:loading.attr="disabled" wire:target="joinWifi"
                                onclick="return confirm('Join this network? The device can come back on a different address.')"
                                class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl text-sm font-medium disabled:opacity-50">Join</button>
                    </form>
                @endif
            @endif
        </section>
    @endif

    {{-- Wireless speakers (WiSA): only models with the hardware get this section --}}
    @if(in_array('wireless_speakers', $capabilities))
        <section class="{{ $card }}" @if($wirelessSpeakers && $wirelessSpeakers['scanning']) wire:poll.3s="reload('wireless_speakers')" @endif>
            <h2 class="{{ $heading }}">Wireless speakers</h2>
            @include('livewire.partials.settings-messages', ['section' => 'wireless_speakers', 'problems' => $problems, 'notices' => $notices])
            @if(!$ready)
                {!! $skeleton !!}
            @elseif($wirelessSpeakers)
                <div class="{{ $row }}">
                    <span class="text-sm text-gray-700 dark:text-gray-300">
                        @if($wirelessSpeakers['scanning']) Scanning for speakers… @else Not scanning @endif
                    </span>
                    @if($wirelessSpeakers['scanning'])
                        <button type="button" wire:click="stopScan" class="px-4 py-2 bg-gray-100 dark:bg-stone-800 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium">Stop</button>
                    @else
                        <button type="button" wire:click="startScan" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl text-sm font-medium disabled:opacity-50">Scan</button>
                    @endif
                </div>
                @forelse($wirelessSpeakers['speakers'] as $speaker)
                    <div class="{{ $row }}" wire:key="wisa-{{ $speaker['id'] }}">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $speaker['name'] }}</span>
                        @if($speaker['state'])<span class="text-xs text-gray-400 dark:text-gray-600">{{ $speaker['state'] }}</span>@endif
                    </div>
                @empty
                    <p class="{{ $muted }}">No wireless speakers found.</p>
                @endforelse
            @endif
        </section>
    @endif

    {{-- Inputs --}}
    @if(in_array('source_activation', $capabilities) && (!$ready || $inputs))
        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">Input</h2>
            @include('livewire.partials.settings-messages', ['section' => 'source_activation', 'problems' => $problems, 'notices' => $notices])
            @if(!$ready)
                {!! $skeleton !!}
            @else
                <div class="divide-y divide-gray-100 dark:divide-stone-800">
                    @foreach($inputs as $input)
                        <div class="{{ $row }}" wire:key="input-{{ $input['source_id'] }}">
                            <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $input['friendly_name'] }}@if($input['shared_from']) <span class="{{ $muted }}">· shared from {{ $input['shared_from'] }}</span>@endif @if($input['in_use']) <span class="{{ $muted }}">· active</span>@endif</span>
                            @unless($input['in_use'])
                                <button type="button" wire:click="switchInput(@js($input['source_id']))" wire:loading.attr="disabled"
                                        class="px-4 py-2 bg-gray-100 dark:bg-stone-800 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium disabled:opacity-50">Switch to</button>
                            @endunless
                        </div>
                    @endforeach
                    <div class="{{ $row }}">
                        <label for="default-input" class="text-sm text-gray-700 dark:text-gray-300">When it wakes with nothing playing, switch to</label>
                        <x-select id="default-input" wire:change="setDefaultSource($event.target.value)">
                            <option value="">Nothing</option>
                            @foreach($inputs as $input)
                                <option value="{{ $input['source_id'] }}" @selected($input['source_id'] === $defaultSource)>{{ $input['friendly_name'] }}@if($input['shared_from']) (shared from {{ $input['shared_from'] }})@endif</option>
                            @endforeach
                        </x-select>
                    </div>
                </div>
            @endif
        </section>
    @endif
</div>
