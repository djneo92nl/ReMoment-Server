<x-app-layout>
    <x-slot name="header">
        <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Insights</h1>
        <p class="mt-1.5 text-gray-500 dark:text-gray-500">Your listening stats across all devices</p>
    </x-slot>

    {{-- Overview numbers --}}
    <div class="grid grid-cols-3 gap-4 mb-8">
        <div class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-6 text-center">
            <p class="text-3xl font-medium text-gray-900 dark:text-gray-100">{{ number_format($totalPlays) }}</p>
            <p class="text-xs text-gray-400 dark:text-gray-600 mt-1 uppercase tracking-wider">Total plays</p>
        </div>
        <div class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-6 text-center">
            <p class="text-3xl font-medium text-gray-900 dark:text-gray-100">{{ number_format($totalTracks) }}</p>
            <p class="text-xs text-gray-400 dark:text-gray-600 mt-1 uppercase tracking-wider">Tracks</p>
        </div>
        <div class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-6 text-center">
            <p class="text-3xl font-medium text-gray-900 dark:text-gray-100">{{ number_format($totalArtists) }}</p>
            <p class="text-xs text-gray-400 dark:text-gray-600 mt-1 uppercase tracking-wider">Artists</p>
        </div>
    </div>

    <div class="grid gap-7 lg:grid-cols-2">

        {{-- Top Artists --}}
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Top Artists</h2>
                <a href="{{ route('artists.index') }}" class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">
                    All artists &rarr;
                </a>
            </div>
            @if($topArtists->isEmpty())
                <p class="text-sm text-gray-400 dark:text-gray-600">No data yet</p>
            @else
                @php $maxPlays = $topArtists->first()->plays_count; @endphp
                <div class="space-y-3">
                    @foreach($topArtists as $i => $artist)
                        <div class="flex items-center gap-3 group">
                            <span class="w-5 text-center text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">{{ $i + 1 }}</span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between mb-1">
                                    <a href="{{ route('artists.show', $artist) }}"
                                       class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate hover:underline">
                                        {{ $artist->name }}
                                    </a>
                                    <span class="text-xs text-gray-400 dark:text-gray-600 flex-shrink-0 ml-2">
                                        {{ number_format($artist->plays_count) }}
                                    </span>
                                </div>
                                <div class="h-1.5 bg-gray-100 dark:bg-stone-800 rounded-full overflow-hidden">
                                    <div class="h-full bg-gray-900 dark:bg-gray-100 rounded-full transition-all"
                                         style="width: {{ $maxPlays > 0 ? round($artist->plays_count / $maxPlays * 100) : 0 }}%"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Top Albums --}}
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
            <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Top Albums</h2>
            @if($topAlbums->isEmpty())
                <p class="text-sm text-gray-400 dark:text-gray-600">No data yet</p>
            @else
                @php $maxPlays = $topAlbums->first()->plays_count; @endphp
                <div class="space-y-3">
                    @foreach($topAlbums as $i => $album)
                        @php $artUrl = $album->images[0]['url'] ?? null; $colors = $album->colors ?? []; @endphp
                        <div class="flex items-center gap-3">
                            <span class="w-5 text-center text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">{{ $i + 1 }}</span>
                            <div class="w-8 h-8 rounded-lg overflow-hidden flex-shrink-0 shadow-sm"
                                 @if(count($colors) >= 2) style="background: linear-gradient(135deg, {{ $colors[0] }}, {{ $colors[1] }})" @else class="bg-gray-100 dark:bg-stone-800" @endif>
                                @if($artUrl)
                                    <img src="{{ $artUrl }}" alt="" class="w-full h-full object-cover">
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between mb-1">
                                    <div class="min-w-0">
                                        <a href="{{ route('albums.show', $album) }}"
                                           class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate hover:underline block">
                                            {{ $album->name }}
                                        </a>
                                        <p class="text-xs text-gray-400 dark:text-gray-600 truncate">
                                            <a href="{{ route('artists.show', $album->artist) }}" class="hover:underline">{{ $album->artist->name }}</a>
                                        </p>
                                    </div>
                                    <span class="text-xs text-gray-400 dark:text-gray-600 flex-shrink-0 ml-2">
                                        {{ number_format($album->plays_count) }}
                                    </span>
                                </div>
                                <div class="h-1.5 bg-gray-100 dark:bg-stone-800 rounded-full overflow-hidden">
                                    <div class="h-full bg-gray-900 dark:bg-gray-100 rounded-full"
                                         style="width: {{ $maxPlays > 0 ? round($album->plays_count / $maxPlays * 100) : 0 }}%"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Plays by hour --}}
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
            <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Listening by Hour</h2>
            @php $maxHour = max($playsByHour) ?: 1; @endphp
            <div class="flex items-end gap-1 h-24">
                @foreach($playsByHour as $hour => $count)
                    <div class="flex-1 flex flex-col items-center gap-1 group">
                        <div class="w-full rounded-t-sm transition-all"
                             style="height: {{ $maxHour > 0 ? round($count / $maxHour * 100) : 0 }}%; min-height: {{ $count > 0 ? '2px' : '0' }}; background: {{ $count > 0 ? '#111827' : '#f3f4f6' }};"
                             title="{{ $hour }}:00 — {{ number_format($count) }} plays"></div>
                    </div>
                @endforeach
            </div>
            <div class="flex gap-1 mt-1">
                @foreach($playsByHour as $hour => $count)
                    <div class="flex-1 text-center">
                        @if($hour % 6 === 0)
                            <span class="text-[9px] text-gray-300 dark:text-stone-600">{{ $hour }}h</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Plays by day of week --}}
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
            <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Listening by Day</h2>
            @php
                $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                $maxDay = max($playsByDay) ?: 1;
            @endphp
            <div class="flex items-end gap-3 h-24">
                @foreach($playsByDay as $dow => $count)
                    <div class="flex-1 flex flex-col items-center gap-1">
                        <div class="w-full rounded-t-sm"
                             style="height: {{ $maxDay > 0 ? round($count / $maxDay * 100) : 0 }}%; min-height: {{ $count > 0 ? '2px' : '0' }}; background: #111827;"
                             title="{{ $dayNames[$dow] }} — {{ number_format($count) }} plays"></div>
                    </div>
                @endforeach
            </div>
            <div class="flex gap-3 mt-2">
                @foreach($playsByDay as $dow => $count)
                    <div class="flex-1 text-center">
                        <span class="text-[10px] text-gray-400 dark:text-gray-600">{{ $dayNames[$dow] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Device activity --}}
        @if($deviceStats->isNotEmpty())
            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8 lg:col-span-2">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Device Activity</h2>
                @php $maxDevicePlays = $deviceStats->first()['plays']; @endphp
                <div class="grid sm:grid-cols-2 gap-4">
                    @foreach($deviceStats as $stat)
                        <div class="flex items-center gap-4">
                            <div class="w-9 h-9 rounded-xl bg-gray-100 dark:bg-stone-800 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-tv text-gray-400 dark:text-gray-500 text-sm"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between mb-1">
                                    <a href="{{ route('devices.show', $stat['device']) }}"
                                       class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate hover:underline">
                                        {{ $stat['device']->device_name }}
                                    </a>
                                    <span class="text-xs text-gray-400 dark:text-gray-600 ml-2 flex-shrink-0">
                                        {{ number_format($stat['plays']) }}
                                    </span>
                                </div>
                                <div class="h-1.5 bg-gray-100 dark:bg-stone-800 rounded-full overflow-hidden">
                                    <div class="h-full bg-gray-900 dark:bg-gray-100 rounded-full"
                                         style="width: {{ $maxDevicePlays > 0 ? round($stat['plays'] / $maxDevicePlays * 100) : 0 }}%"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

    </div>
</x-app-layout>
