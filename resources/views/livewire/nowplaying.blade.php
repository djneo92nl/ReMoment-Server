<div wire:poll.1s>
    @if($device->state !== \App\Domain\Device\State::Unreachable)
        <div class="grid grid-flow-col min-w-32 grid-rows-3 gap-4">
            <div class="row-span-3">
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

                <div class="volume">
                    🔊 Volume: {{ $volume }}
                    <input type="range" min="0" max="100" wire:model="volume">
                </div>
            </div>

            <div class="col-span-2">
                <h3 class="text-gray-900 dark:text-white text-base font-medium tracking-tight ">{{$device->device_name}} {{$device->id}}</h3>

            </div>

            <div class="flex items-center col-span-2 row-span-2 min-w-full w-full space-x-6 p-6 bg-gray-600 rounded-xl shadow-lg">

                @if($device->state !== \App\Domain\Device\State::Standby)

                    @if(isset($nowPlaying['type']) &&  $nowPlaying['type'] === 'music')
                        <img src="{{  $nowPlaying['track']['images'][0]['url'] }}" alt="Album Cover" class="w-40 h-40 mr-2 rounded-lg object-cover">
                        <div class="flex flex-col space-y-3 w-full">
                            <div>
                                <h1 class="text-3xl font-semibold">{{ $nowPlaying['track']['name'] }}</h1>
                                @isset($nowPlaying['artist'])<p class="text-gray-300 text-lg">{{ $nowPlaying['artist']['name'] }}</p>@endisset
                                @isset($nowPlaying['album'])<p  class="text-gray-500">{{ $nowPlaying['album']['name'] }}</p>@endisset
                                @isset($nowPlaying['radio'])<p  class="text-gray-500">{{ $nowPlaying['radio']['name'] }}</p>@endisset
                            </div>
                            @if(isset($nowPlaying['radio']))
                                <div>
                                    <div class="w-full h-1 bg-gray-700 rounded-full">
                                        <div class="h-1 bg-white rounded-full" style="width:100%"></div>
                                    </div>
                                    <div class="flex justify-end text-sm  text-gray-400 mt-1">
                                        <span class="">{{ \App\Domain\Helpers\TimeHelper::class::secondsToMinutes($nowPlaying['position'])  }}</span>
                                    </div>
                                </div>
                            @else
                                <div>
                                    <div class="w-full h-1 bg-gray-700 rounded-full">
                                        <div class="h-1 bg-white rounded-full" style="width:{{(int)( (   $nowPlaying['position'] / $nowPlaying['track']['duration'] ) *100 ) }}%"></div>
                                    </div>
                                    <div class="flex justify-between text-sm text-gray-400 mt-1">
                                        <span>{{ \App\Domain\Helpers\TimeHelper::class::secondsToMinutes($nowPlaying['position'])  }}</span>
                                        <span>{{ \App\Domain\Helpers\TimeHelper::class::secondsToMinutes($nowPlaying['track']['duration'])  }}</span>
                                    </div>
                                </div>
                            @endif

                            @isset($nowPlaying['state'])
                                <div class="flex justify-center space-x-6 mt-2">
                                    <button wire:click="previous" class="material-icons text-4xl text-gray-300 hover:text-white">  ⏮️</button>
                                    @if($nowPlaying['state'] === 'pause')
                                        <button wire:click="play" class="material-icons text-5xl text-white hover:text-gray-300"> ▶️ </button>
                                    @else
                                        <button wire:click="pause" class="material-icons text-5xl text-white hover:text-gray-300"> ⏸️ </button>
                                    @endif
                                    <button  wire:click="next" class="material-icons text-4xl text-gray-300 hover:text-white">⏭️</button>
                                </div>
                            @endisset
                        </div>
                    @endif
                    @if(isset($nowPlaying['type']) &&  $nowPlaying['type'] === 'video')
                        <div class="flex flex-col space-y-3 w-full">
                            <div>
                                <h1 class="text-3xl font-semibold">{{ $nowPlaying['source']['name'] }}</h1>
                                @isset($nowPlaying['source'])<p class="text-gray-300 text-lg">{{ $nowPlaying['source']['sourceType'] }}  {{ $nowPlaying['source']['connector'] }}</p>@endisset
                            </div>


                            @isset($nowPlaying['state'])
                                <div class="flex justify-center space-x-6 mt-2">
                                    <button wire:click="previous" class="material-icons text-4xl text-gray-300 hover:text-white">  ⏮️</button>
                                    @if($nowPlaying['state'] === 'pause')
                                        <button wire:click="play" class="material-icons text-5xl text-white hover:text-gray-300"> ▶️ </button>
                                    @else
                                        <button wire:click="pause" class="material-icons text-5xl text-white hover:text-gray-300"> ⏸️ </button>
                                    @endif
                                    <button  wire:click="next" class="material-icons text-4xl text-gray-300 hover:text-white">⏭️</button>
                                </div>
                            @endisset
                        </div>
                    @endif
                @endif


            </div>
        </div>
    @else
        <h3 class="text-gray-900 dark:text-white text-base font-medium tracking-tight ">{{$device->device_name}} {{$device->id}}</h3>

    @endif</div>
