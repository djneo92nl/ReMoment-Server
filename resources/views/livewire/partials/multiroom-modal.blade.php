    {{-- ── Multiroom picker: tiles for the rooms, a check marks who is in the session (B&O style) ── --}}
        <x-modal name="multiroom-{{ $device->id }}" maxWidth="md">
            @php
                $isHosting = $state === \App\Domain\Device\State::Playing;
                $inSession = array_column($currentListeners, 'id');
                $subtitle = $isHosting && count($currentListeners) > 0 ? $device->device_name.' + '.count($currentListeners) : $device->device_name;
            @endphp
            <div class="bg-white dark:bg-stone-900" style="padding:1.25rem 1.25rem 1.5rem">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1rem">
                    <div>
                        <h3 class="text-gray-900 dark:text-gray-100" style="font-size:1.25rem;font-weight:700;letter-spacing:.01em">Multiroom</h3>
                        <p style="font-size:.9rem;font-weight:600;color:#d99a00">{{ $subtitle }}</p>
                    </div>
                    <button @click="$dispatch('close')"
                            class="flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                        <i class="fa-solid fa-xmark text-xs"></i>
                    </button>
                </div>

                @if(!$multiRoomDataLoaded)
                    <div style="padding:2rem 0;text-align:center">
                        <i class="fa-solid fa-circle-notch fa-spin text-gray-300 dark:text-stone-600 text-2xl"></i>
                    </div>
                @else
                    @if($multiroomError)
                        <p class="text-red-500 dark:text-red-400" style="font-size:.8rem;margin-bottom:.75rem">{{ $multiroomError }}</p>
                    @endif

                    @php
                        $peerIds = array_merge(array_column($currentListeners, 'id'), array_column($invitableDevices, 'id'), array_column($joinableSessions, 'id'));
                        $rooms = \App\Models\Device::whereIn('id', $peerIds)->get()->keyBy('id');
                    @endphp
                    <div style="display:flex;flex-direction:column;gap:.6rem;max-height:60vh;overflow-y:auto">
                        @if($isHosting)
                            <x-mini-player :device="$device" volume-method="setMemberVolume" tag="Host" :in-session="true" wire:key="mr-host" />

                            @foreach($currentListeners as $member)
                                @continue(!$rooms->has($member['id']))
                                <x-mini-player :device="$rooms[$member['id']]" volume-method="setMemberVolume" :in-session="true"
                                               :toggle="'removeListener('.$member['id'].')'" wire:key="mr-in-{{ $member['id'] }}" />
                            @endforeach

                            @foreach($invitableDevices as $guest)
                                @continue(in_array($guest['id'], $inSession, true) || !$rooms->has($guest['id']))
                                <x-mini-player :device="$rooms[$guest['id']]" :in-session="false"
                                               :toggle="'inviteDevice('.$guest['id'].')'" wire:key="mr-out-{{ $guest['id'] }}" />
                            @endforeach
                        @else
                            {{-- not playing: join a room that is --}}
                            @foreach($joinableSessions as $session)
                                @continue(!$rooms->has($session['id']))
                                <x-mini-player :device="$rooms[$session['id']]" :in-session="false" :toggle="'joinSession('.$session['id'].')'"
                                               :close-on-toggle="true" wire:key="mr-join-{{ $session['id'] }}" />
                            @endforeach
                        @endif
                    </div>

                    @if(($isHosting && count($currentListeners) === 0 && count($invitableDevices) === 0) || (!$isHosting && count($joinableSessions) === 0))
                        <p class="text-gray-400 dark:text-gray-600" style="font-size:.85rem;text-align:center;padding:1rem 0 0">
                            {{ $isHosting ? "No other {$device->device_brand_name} devices available." : 'Nothing is playing that this device can join.' }}
                        </p>
                    @endif
                @endif
            </div>
        </x-modal>
