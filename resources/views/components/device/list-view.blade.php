<div class="bg-white  dark:bg-gray-800 rounded-lg px-6 py-4 ring shadow-xl ring-gray-900/5">
    <livewire:nowplaying
        :device="$device"
    />

{{--    @if($device->state !== \App\Domain\Device\State::Unreachable)--}}
{{--        <div class="grid grid-flow-col grid-rows-3 gap-4">--}}
{{--            <div class="row-span-3">--}}
{{--                <table>--}}
{{--                    <caption class="caption-bottom">--}}
{{--                        Meta info--}}
{{--                    </caption>--}}
{{--                    <tr>--}}
{{--                        <td>Volume : </td>--}}
{{--                        <td>{{ $device->driver->getVolume() }}</td>--}}
{{--                    </tr>--}}
{{--                    <tr>--}}
{{--                        <td>Volume : </td>--}}
{{--                        <td>{{  \App\Domain\Device\DeviceCache::getLastSeen($device->id) }}</td>--}}
{{--                    </tr>                    <tr>--}}
{{--                        <td>State : </td>--}}
{{--                        <td>{{  \App\Domain\Device\DeviceCache::getState($device->id) }}</td>--}}
{{--                    </tr>--}}

{{--                </table>--}}
{{--            </div>--}}

{{--            <div class="col-span-2">--}}
{{--                <h3 class="text-gray-900 dark:text-white text-base font-medium tracking-tight ">{{$device->device_name}} {{$device->id}}</h3>--}}

{{--            </div>--}}

{{--            <div class="flex items-center col-span-2 row-span-2  space-x-6 p-6 bg-gray-600 mt- rounded-xl shadow-lg">--}}

{{--                @if($device->state !== \App\Domain\Device\State::Standby)--}}
{{--                    @php ($nowPlaying = $device->currentPlaying)--}}
{{--                    <img src="{{  $nowPlaying['track']['images'][0]['url'] }}" alt="Album Cover" class="w-40 h-40 rounded-lg object-cover">--}}
{{--                    <div class="flex flex-col space-y-3 w-full">--}}
{{--                        <div>--}}
{{--                            <h1 class="text-3xl font-semibold">{{ $nowPlaying['track']['name'] }}</h1>--}}
{{--                            <p class="text-gray-300 text-lg">{{ $nowPlaying['artist']['name'] }}</p>--}}
{{--                            @isset($nowPlaying['album'])<p  class="text-gray-500">{{ $nowPlaying['album']['name'] }}</p>@endisset--}}
{{--                        </div>--}}
{{--                        @if(isset($nowPlaying['radio']))--}}
{{--                            <div>--}}
{{--                                <div class="w-full h-1 bg-gray-700 rounded-full">--}}
{{--                                    <div class="h-1 bg-white rounded-full" style="width:100%"></div>--}}
{{--                                </div>--}}
{{--                                <div class="flex justify-end text-sm  text-gray-400 mt-1">--}}
{{--                                    <span class="">{{ \App\Domain\Helpers\TimeHelper::class::secondsToMinutes($nowPlaying['position'])  }}</span>--}}
{{--                                </div>--}}
{{--                            </div>--}}
{{--                        @else--}}
{{--                            <div>--}}
{{--                                <div class="w-full h-1 bg-gray-700 rounded-full">--}}
{{--                                    <div class="h-1 bg-white rounded-full" style="width:{{(int)( (   $nowPlaying['position'] / $nowPlaying['track']['duration'] ) *100 ) }}%"></div>--}}
{{--                                </div>--}}
{{--                                <div class="flex justify-between text-sm text-gray-400 mt-1">--}}
{{--                                    <span>{{ $nowPlaying['position'] }}</span>--}}
{{--                                    <span>{{ $nowPlaying['track']['duration']  }}</span>--}}
{{--                                </div>--}}
{{--                            </div>--}}
{{--                        @endif--}}
{{--                        <div class="flex justify-center space-x-6 mt-2">--}}
{{--                            <button class="material-icons text-4xl text-gray-300 hover:text-white">skip_previous</button>--}}
{{--                            <button class="material-icons text-5xl text-white hover:text-gray-300">play_arrow</button>--}}
{{--                            <button class="material-icons text-4xl text-gray-300 hover:text-white">skip_next</button>--}}
{{--                        </div>--}}
{{--                    </div>--}}
{{--                @endif--}}
{{--            </div>--}}
{{--        </div>--}}
{{--    @else--}}
{{--        <h3 class="text-gray-900 dark:text-white text-base font-medium tracking-tight ">{{$device->device_name}} {{$device->id}}</h3>--}}

{{--    @endif--}}

</div>
