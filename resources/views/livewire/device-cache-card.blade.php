@php
    $stateEnum = $state ? \App\Domain\Device\State::from($state) : null;
    $np = $nowPlaying;
@endphp

<div wire:poll.2s class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8 space-y-5">

    <div class="flex items-center justify-between">
        <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">Cache</h2>
        <span class="text-xs text-gray-400 dark:text-gray-600 font-mono">device:{{ $device->id }}</span>
    </div>

    {{-- State + Listener --}}
    <dl class="space-y-3 text-sm">
        <div class="flex justify-between gap-4">
            <dt class="text-gray-500 dark:text-gray-500">State</dt>
            <dd>
                @if($stateEnum === \App\Domain\Device\State::Playing)
                    <span class="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-500 font-medium">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>Playing
                    </span>
                @elseif($stateEnum === \App\Domain\Device\State::Paused)
                    <span class="inline-flex items-center gap-1.5 text-amber-600 dark:text-amber-500 font-medium">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>Paused
                    </span>
                @elseif($stateEnum === \App\Domain\Device\State::Standby)
                    <span class="inline-flex items-center gap-1.5 text-red-600 dark:text-red-500 font-medium">
                        <span class="w-2 h-2 rounded-full bg-red-500"></span>Standby
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-gray-400 dark:text-gray-600 font-medium">
                        <span class="w-2 h-2 rounded-full bg-gray-400"></span>{{ $state ?? 'Unreachable' }}
                    </span>
                @endif
            </dd>
        </div>

        <div class="flex justify-between gap-4">
            <dt class="text-gray-500 dark:text-gray-500">Listener</dt>
            <dd>
                @if($listenerRunning)
                    <span class="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-500 font-medium">
                        <span class="relative flex w-2 h-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full w-2 h-2 bg-emerald-500"></span>
                        </span>
                        Running
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-gray-400 dark:text-gray-600 font-medium">
                        <span class="w-2 h-2 rounded-full bg-gray-300 dark:bg-stone-600"></span>Stopped
                    </span>
                @endif
            </dd>
        </div>

        <div class="flex justify-between gap-4">
            <dt class="text-gray-500 dark:text-gray-500">Last seen</dt>
            <dd class="font-medium text-gray-800 dark:text-gray-200 text-right text-xs">
                {{ $lastSeen ? \Carbon\Carbon::parse($lastSeen)->diffForHumans() : '—' }}
            </dd>
        </div>
    </dl>

    @if($np)
        <div class="border-t border-gray-100 dark:border-stone-800 pt-5 space-y-3">

            <p class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Now Playing</p>

            <dl class="space-y-2.5 text-sm">

                {{-- Platform / type --}}
                @if($np->platform || $np->type)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Platform</dt>
                        <dd class="font-mono text-xs text-gray-700 dark:text-gray-300">
                            {{ $np->platform }}{{ $np->type ? ' / ' . $np->type : '' }}
                        </dd>
                    </div>
                @endif

                {{-- Source --}}
                @if($np->source)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Source</dt>
                        <dd class="text-right text-xs">
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ $np->source->name ?? '—' }}</span>
                            @if($np->source->sourceType)
                                <span class="ml-1 text-gray-400 dark:text-gray-600 font-mono">{{ $np->source->sourceType }}</span>
                            @endif
                            @if($np->source->connector)
                                <br><span class="text-gray-400 dark:text-gray-600 font-mono">{{ $np->source->connector }}</span>
                            @endif
                            @if($np->source->category)
                                <br><span class="text-gray-400 dark:text-gray-600">{{ $np->source->category }}</span>
                            @endif
                        </dd>
                    </div>
                @endif

                {{-- Radio --}}
                @if($np->radio)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Radio</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200 text-right text-xs">{{ $np->radio->name }}</dd>
                    </div>
                @endif

                {{-- Track --}}
                @if($np->track)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Track</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200 text-right text-xs max-w-48 truncate">{{ $np->track->name ?? '—' }}</dd>
                    </div>
                    @if($np->track->artist)
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-500">Artist</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-200 text-right text-xs max-w-48 truncate">{{ $np->track->artist->name }}</dd>
                        </div>
                    @endif
                    @if(($np->track->duration ?? 0) > 0)
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-500">Duration</dt>
                            <dd class="font-mono text-xs text-gray-600 dark:text-gray-400">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($np->track->duration) }}</dd>
                        </div>
                    @endif
                @endif

                {{-- Album --}}
                @if($np->album)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Album</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200 text-right text-xs max-w-48 truncate">{{ $np->album->name }}</dd>
                    </div>
                @endif

                {{-- Position --}}
                @if($np->position > 0)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-500">Position</dt>
                        <dd class="font-mono text-xs text-gray-600 dark:text-gray-400">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($np->position) }}</dd>
                    </div>
                @endif

            </dl>
        </div>
    @else
        <div class="border-t border-gray-100 dark:border-stone-800 pt-4">
            <p class="text-xs text-gray-400 dark:text-gray-600 italic">No now_playing in cache</p>
        </div>
    @endif

</div>
