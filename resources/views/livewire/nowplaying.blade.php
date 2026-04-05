<div wire:poll.1s>
    @if($device->state !== \App\Domain\Device\State::Unreachable)
        <!-- Now Playing Card -->
        <div class="md:col-span-2 bg-white rounded-3xl shadow-xl border border-gray-200/80 dark:border-stone-900/80 overflow-hidden hover:shadow-2xl dark:bg-stone-900 transition-all">
            <div class="p-8 md:p-10">
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-3">
                        @if($device->state !== \App\Domain\Device\State::Standby)
                            <div class="relative">
                                <div class="w-4 h-4 rounded-full bg-emerald-500 animate-pulse ring-4 ring-emerald-400/20"></div>
                            </div>
                            <span class="text-base font-medium uppercase tracking-wider text-emerald-600 dark:text-emerald-500">Now Playing</span>
                        @else
                            <div class="relative">
                                <div class="w-4 h-4 rounded-full bg-red-500 ring-4 ring-red-400/20"></div>
                            </div>
                            <span class="text-base font-medium uppercase tracking-wider text-red-600 dark:text-red-500">Standby</span>
                        @endif
                    </div>
                    <span class="text-sm text-gray-600 dark:text-gray-400">{{ $device->device_name }}</span>
                </div>

                @if($device->state !== \App\Domain\Device\State::Standby)
                    @php($nowPlaying = $device->currentPlaying)

                    @if(isset($nowPlaying['type']) && $nowPlaying['type'] === 'music')
                        <div class="flex flex-col lg:flex-row gap-8">
                            <!-- Album Artwork -->
                            <div class="w-full lg:w-64 aspect-square rounded-2xl overflow-hidden shadow-lg ring-1 ring-gray-200 dark:ring-stone-900">
                                <img src="{{ $nowPlaying['track']['images'][0]['url'] ?? 'https://via.placeholder.com/400' }}" alt="Album" class="w-full h-full object-cover">
                            </div>

                            <div class="flex-1 space-y-6">
                                <!-- Track Info -->
                                <div>
                                    <h2 class="text-3xl lg:text-4xl dark:text-emerald-50 font-medium tracking-tight">{{ $nowPlaying['track']['name'] }}</h2>
                                    <p class="mt-2 text-xl text-gray-600 dark:text-gray-400">
                                        @isset($nowPlaying['artist'])
                                            {{ $nowPlaying['artist']['name'] }}
                                        @endisset
                                        @isset($nowPlaying['album'])
                                            • {{ $nowPlaying['album']['name'] }}
                                        @endisset
                                    </p>
                                </div>

                                <div class="space-y-6">
                                    <!-- Progress Bar -->
                                    <div>
                                        <div class="flex justify-between text-sm text-gray-500 mb-2">
                                            <span>{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($nowPlaying['position']) }}</span>
                                            <span>{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($nowPlaying['track']['duration']) }}</span>
                                        </div>
                                        <div class="h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                            <div class="h-full bg-gradient-to-r from-red-500 to-rose-600 rounded-full" style="width:{{ (int)(($nowPlaying['position'] / $nowPlaying['track']['duration']) * 100) }}%"></div>
                                        </div>
                                    </div>

                                    <!-- Playback Controls -->
                                    @isset($nowPlaying['state'])
                                        <div class="flex items-center justify-center lg:justify-start gap-10">
                                            <button wire:click="previous" class="text-gray-400 dark:text-gray-300 hover:text-gray-800 text-2xl">
                                                <i class="fa-solid fa-backward-step"></i>
                                            </button>
                                            @if($nowPlaying['state'] === 'pause')
                                                <button wire:click="play" class="text-gray-900 dark:text-gray-200 hover:scale-110 transition-transform">
                                                    <i class="fa-solid fa-play-circle text-6xl drop-shadow"></i>
                                                </button>
                                            @else
                                                <button wire:click="pause" class="text-gray-900 dark:text-gray-200 hover:scale-110 transition-transform">
                                                    <i class="fa-solid fa-pause-circle text-6xl drop-shadow"></i>
                                                </button>
                                            @endif
                                            <button wire:click="next" class="text-gray-400 dark:text-gray-300 hover:text-gray-800 text-2xl">
                                                <i class="fa-solid fa-forward-step"></i>
                                            </button>
                                        </div>
                                    @endisset

                                    <!-- Volume Control -->
                                    <div class="flex items-center gap-4 max-w-md">
                                        <i class="fa-solid fa-volume-high text-gray-500 w-5"></i>
                                        <input type="range" min="0" max="100" wire:model="volume" class="flex-1 h-1.5 appearance-none rounded-full bg-gray-200 accent-gray-700 dark:accent-stone-600">
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-400 w-8">{{ $volume }}</span>
                                    </div>

                                    <!-- Standby Button -->
                                    <div class="pt-4">
                                        <button wire:click="standby" class="px-6 py-2 bg-red-500 hover:bg-red-600 text-white rounded-full text-sm font-medium transition-colors">
                                            <i class="fa-solid fa-power-off mr-2"></i>Standby
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <!-- No Music Playing - Show Device Info -->
                        <div class="flex items-center justify-center h-48 text-gray-400">
                            <div class="text-center">
                                <i class="fa-solid fa-music text-6xl opacity-25 mb-4"></i>
                                <p>No music playing</p>
                            </div>
                        </div>
                    @endif
                @else
                    <!-- Standby State -->
                    <div class="flex items-center justify-center h-48 text-gray-400">
                        <div class="text-center">
                            <i class="fa-solid fa-power-off text-6xl opacity-25 mb-4"></i>
                            <p class="text-lg">Device in Standby</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @else
        <!-- Unreachable State -->
        <div class="bg-white rounded-3xl shadow-lg border border-gray-200/70 dark:bg-stone-900 dark:border-stone-900/80 p-8">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-3.5 h-3.5 rounded-full bg-gray-400"></div>
                <h3 class="text-xl font-medium dark:text-gray-100">{{ $device->device_name }}</h3>
            </div>
            <div class="text-gray-500">Device unreachable last seen at {{ $device->last_seen->toRfc850String() }}</div>
        </div>
    @endif
</div>
