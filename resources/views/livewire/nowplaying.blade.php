<div wire:poll.1s>
    @dump($device->state)
    @if($device->state !== \App\Domain\Device\State::Unreachable)
        <div class="grid grid-flow-col min-w-full grid-rows-3 ml-2 gap-4">
            <div class="row-span-3 text-slate-400">
                <table>
                    {{--                    <caption class="caption-bottom">--}}
                    {{--                        Meta info--}}
                    {{--                    </caption>--}}
                    <tr>
                        <td>Volume : </td>
                        <td>{{ $device->driver->getVolume() }}</td>
                    </tr>
                    <tr>
                        <td>Last Seen : </td>
                        <td>{{  \App\Domain\Device\DeviceCache::getLastSeen($device->id) }}</td>
                    </tr>                    <tr>
                        <td>State : </td>
                        <td>{{  \App\Domain\Device\DeviceCache::getState($device->id) }}</td>
                    </tr>
                    @if($device->state !== \App\Domain\Device\State::Standby)
                        @php ($nowPlaying = $device->currentPlaying)

                    @endif

                    <button wire:click="standby" class="material-icons text-5xl text-white hover:text-gray-300"> 🔴️ </button>


                </table>

                <div class="mt-4 flex items-center gap-4">
                    <span class="text-xs text-slate-400">Vol</span>

                    <input type="range" min="0" max="100"
                           class="h-1 w-48 appearance-none rounded-full bg-slate-200 accent-slate-800"
                           wire:model="volume">
                </div>
            </div>

            <div class="col-span-2">
                <h3 class="text-gray-900 dark:text-white text-base font-medium tracking-tight ">{{$device->device_name}} {{$device->id}}</h3>

            </div>

            <div class="flex items-center gap-8 col-span-2 row-span-2 min-w-full rounded-2xl bg-slate-100 px-8 py-6">

                @if($device->state !== \App\Domain\Device\State::Standby)

                    @if(isset($nowPlaying['type']) && $nowPlaying['type'] === 'music')

                        <!-- Artwork -->
                        <img
                            src="{{ $nowPlaying['track']['images'][0]['url'] }}"
                            alt="Album cover"
                            class="h-20 w-20 rounded-xl object-cover shadow-sm"
                        >

                        <!-- Track info -->
                        <div class="flex-1 min-w-0">
                            <div class="text-base font-medium text-slate-900 truncate">
                                {{ $nowPlaying['track']['name'] }}
                            </div>

                            @isset($nowPlaying['artist'])
                                <div class="text-sm text-slate-500 truncate">
                                    {{ $nowPlaying['artist']['name'] }}
                                </div>
                            @endisset

                            @isset($nowPlaying['album'])
                                <div class="text-xs text-slate-400 truncate">
                                    {{ $nowPlaying['album']['name'] }}
                                </div>
                            @endisset

                            <!-- Progress -->
                            <div class="mt-3">
                                <div class="h-[2px] w-full bg-slate-300">
                                    <div
                                        class="h-[2px]  bg-slate-800"
                                        style="width:{{ (int)(($nowPlaying['position'] / $nowPlaying['track']['duration']) * 100) }}%"
                                    ></div>
                                </div>

                                <div class="mt-1 flex justify-between text-[11px] text-slate-400">
                                    <span>
                                        {{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($nowPlaying['position']) }}
                                    </span>
                                    <span>
                                        {{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($nowPlaying['track']['duration']) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Controls -->
                        @isset($nowPlaying['state'])
                            <div class="flex items-center gap-4">
                                <button
                                    wire:click="previous"
                                    class="h-10 w-10 rounded-full bg-white shadow-sm transition hover:scale-105"
                                >
                                    ⏮
                                </button>

                                @if($nowPlaying['state'] === 'pause')
                                    <button
                                        wire:click="play"
                                        class="h-12 w-12 rounded-full bg-white shadow transition hover:scale-105"
                                    >
                                        ▶
                                    </button>
                                @else
                                    <button
                                        wire:click="pause"
                                        class="h-12 w-12 rounded-full bg-white shadow transition hover:scale-105"
                                    >
                                        ⏸
                                    </button>
                                @endif

                                <button
                                    wire:click="next"
                                    class="h-10 w-10 rounded-full bg-white shadow-sm transition hover:scale-105"
                                >
                                    ⏭
                                </button>
                            </div>
                        @endisset

                    @endif
                @endif
            </div>
        </div>
    @else
        <h3 class="text-gray-900 dark:text-white text-base font-medium tracking-tight ">{{$device->device_name}} {{$device->id}}</h3>
    @endif
</div>
