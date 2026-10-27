{{-- The multiroom picker's body: inside a modal (device card) or the player bar's panel. --}}
    @php
        $inPanel = $inPanel ?? false;
        $isHosting = $state === \App\Domain\Device\State::Playing;
        $inSession = array_column($currentListeners, 'id');
        $subtitle = $isHosting && count($currentListeners) > 0 ? $device->device_name.' + '.count($currentListeners) : $device->device_name;
    @endphp
    <div class="{{ $inPanel ? '' : 'bg-white dark:bg-stone-900' }}" style="padding:{{ $inPanel ? '1rem' : '1.25rem 1.25rem 1.5rem' }};{{ $inPanel ? 'display:flex;flex-direction:column;min-height:0;overflow:hidden;' : '' }}">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1rem">
            <div>
                <h3 class="{{ $inPanel ? '' : 'text-gray-900 dark:text-gray-100' }}" style="font-size:1.25rem;font-weight:700;letter-spacing:.01em">Multiroom</h3>
                <p class="text-sm font-semibold text-amber-600">{{ $subtitle }}</p>
            </div>
            <x-modal-close :class="$inPanel ? '!text-white/70' : ''" />
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
                $rooms = $this->multiroomRooms;
                // Rooms to add or join: the playing ones first, the unreachable ones not at all
                $rank = fn ($id) => $rooms[$id]->liveState() === \App\Domain\Device\State::Playing ? 0 : 1;
                $reachable = fn (array $list) => collect($list)->filter(fn ($r) => $rooms->has($r['id']) && $rooms[$r['id']]->liveState() !== \App\Domain\Device\State::Unreachable)
                    ->sortBy(fn ($r) => $rank($r['id']))->values()->all();
                $invitableDevices = $reachable($invitableDevices);
                $joinableSessions = $reachable($joinableSessions);
            @endphp
            <div style="display:flex;flex-direction:column;gap:.6rem;overflow-y:auto;{{ $inPanel ? 'flex:1 1 auto;min-height:0;' : 'max-height:60vh;' }}">
                @if($isHosting)
                    <x-mini-player :glass="$inPanel" :device="$device" volume-method="setMemberVolume" tag="Host" :in-session="true" wire:key="mr-host" />

                    @foreach($currentListeners as $member)
                        @continue(!$rooms->has($member['id']))
                        <x-mini-player :glass="$inPanel" :device="$rooms[$member['id']]" volume-method="setMemberVolume" :in-session="true"
                                       :toggle="'removeListener('.$member['id'].')'" wire:key="mr-in-{{ $member['id'] }}" />
                    @endforeach

                    @foreach($invitableDevices as $guest)
                        @continue(in_array($guest['id'], $inSession, true) || !$rooms->has($guest['id']))
                        <x-mini-player :glass="$inPanel" :device="$rooms[$guest['id']]" :in-session="false"
                                       :toggle="'inviteDevice('.$guest['id'].')'" wire:key="mr-out-{{ $guest['id'] }}" />
                    @endforeach

                    @if(count($streamTargets) > 0)
                        <p class="{{ $inPanel ? '' : 'text-gray-400 dark:text-gray-600' }}" style="{{ $inPanel ? 'opacity:.6;' : '' }}font-size:.7rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;margin-top:.4rem"
                           title="Played as a stream: about 5-10 seconds behind, not in sync with the B&O speakers">Stream to</p>
                        @foreach($streamTargets as $target)
                            @continue(!$rooms->has($target['id']))
                            @php $streaming = in_array($target['id'], $streamPlaying, true); @endphp
                            <x-mini-player :glass="$inPanel" :device="$rooms[$target['id']]" :in-session="$streaming"
                                           :toggle="($streaming ? 'stopStreamTo(' : 'streamTo(').$target['id'].')'" wire:key="mr-stream-{{ $target['id'] }}" />
                        @endforeach
                    @endif
                @else
                    {{-- not playing: join a room that is --}}
                    @foreach($joinableSessions as $session)
                        @continue(!$rooms->has($session['id']))
                        <x-mini-player :glass="$inPanel" :device="$rooms[$session['id']]" :in-session="false" :toggle="'joinSession('.$session['id'].')'"
                                       :close-on-toggle="true" wire:key="mr-join-{{ $session['id'] }}" />
                    @endforeach
                @endif
            </div>

            @if(($isHosting && count($currentListeners) === 0 && count($invitableDevices) === 0 && count($streamTargets) === 0) || (!$isHosting && count($joinableSessions) === 0))
                <p class="{{ $inPanel ? '' : 'text-gray-400 dark:text-gray-600' }}" style="{{ $inPanel ? 'opacity:.6;' : '' }}font-size:.85rem;text-align:center;padding:1rem 0 0">
                    {{ $isHosting ? "No other {$device->device_brand_name} devices available." : 'Nothing is playing that this device can join.' }}
                </p>
            @endif
        @endif
    </div>
