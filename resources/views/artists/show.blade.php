<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start gap-4">
            <a href="{{ route('artists.index') }}"
               class="mt-1 flex items-center justify-center w-9 h-9 rounded-xl bg-gray-100 dark:bg-stone-800 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-stone-700 transition-colors flex-shrink-0">
                <i class="fa-solid fa-arrow-left text-sm"></i>
            </a>
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">{{ $artist->name }}</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">
                    {{ number_format($totalPlays) }} {{ Str::plural('play', $totalPlays) }}
                    @if($totalSeconds > 0)
                        @php $hours = floor($totalSeconds / 3600); $mins = floor(($totalSeconds % 3600) / 60); @endphp
                        &middot;
                        {{ $hours > 0 ? "{$hours}h {$mins}m" : "{$mins}m" }} listened
                    @endif
                </p>
            </div>
        </div>
    </x-slot>

    <div class="grid gap-7 lg:grid-cols-3">

        <!-- Left: Top tracks + Albums -->
        <div class="lg:col-span-2 space-y-6">

            @if($topTracks->isNotEmpty())
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Top Tracks</h2>
                    <div class="space-y-1">
                        @foreach($topTracks as $i => $track)
                            @php
                                $artUrl = $track->images[0]['url'] ?? $track->album?->images[0]['url'] ?? null;
                            @endphp
                            <div class="flex items-center gap-4 p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-stone-800/50 transition-colors group">
                                <span class="w-5 text-center text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">{{ $i + 1 }}</span>
                                <div class="w-9 h-9 rounded-lg overflow-hidden bg-gray-100 dark:bg-stone-800 flex-shrink-0">
                                    @if($artUrl)
                                        <img src="{{ $artUrl }}" alt="" class="w-full h-full object-cover">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <i class="fa-solid fa-music text-gray-300 dark:text-stone-600 text-xs"></i>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $track->name }}</p>
                                    @if($track->album)
                                        <p class="text-xs text-gray-400 dark:text-gray-600 truncate mt-0.5">
                                            <a href="{{ route('albums.show', $track->album) }}" class="hover:underline">{{ $track->album->name }}</a>
                                        </p>
                                    @endif
                                </div>
                                <span class="text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">
                                    {{ number_format($track->plays_count) }} {{ Str::plural('play', $track->plays_count) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($artist->albums->isNotEmpty())
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Albums</h2>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        @foreach($artist->albums as $album)
                            @php
                                $artUrl = $album->images[0]['url'] ?? null;
                                $colors = $album->colors ?? [];
                            @endphp
                            <a href="{{ route('albums.show', $album) }}"
                               class="group text-center">
                                <div class="aspect-square rounded-2xl overflow-hidden mb-3 shadow-sm ring-1 ring-gray-100 dark:ring-stone-800"
                                     @if(count($colors) >= 2) style="background: linear-gradient(135deg, {{ $colors[0] }}, {{ $colors[1] }})" @else style="background: #f3f4f6" @endif>
                                    @if($artUrl)
                                        <img src="{{ $artUrl }}" alt="{{ $album->name }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <i class="fa-solid fa-compact-disc text-2xl text-white/50"></i>
                                        </div>
                                    @endif
                                </div>
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate group-hover:underline">{{ $album->name }}</p>
                                @if($album->released_at)
                                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">{{ $album->released_at->format('Y') }}</p>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <!-- Right: Recent plays -->
        <div>
            @if($recentPlays->isNotEmpty())
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Recent Plays</h2>
                    <div class="space-y-3">
                        @foreach($recentPlays as $play)
                            <div class="flex items-center gap-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ $play->track->name }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-600 truncate mt-0.5">
                                        {{ $play->played_at->format('M j') }}
                                        @if($play->device)
                                            &middot; {{ $play->device->device_name }}
                                        @endif
                                    </p>
                                </div>
                                @if($play->skipped)
                                    <span class="text-[10px] text-amber-500 dark:text-amber-400 flex-shrink-0" title="Skipped">
                                        <i class="fa-solid fa-forward-step"></i>
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-5 pt-4 border-t border-gray-100 dark:border-stone-800">
                        <a href="{{ route('history.index') }}"
                           class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">
                            Full history &rarr;
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
