@php
    $stateEnum = $state ? \App\Domain\Device\State::from($state) : null;
    $np = $nowPlaying;
@endphp

<x-card wire:poll.2s class="p-8 space-y-5">

    <div class="flex items-center justify-between">
        <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">Cache</h2>
        <span class="text-xs text-gray-400 dark:text-gray-600 font-mono">device:{{ $device->id }}</span>
    </div>

    {{-- State + Listener --}}
    <dl class="space-y-3 text-sm">
        <div class="flex justify-between gap-4">
            <dt class="text-gray-500">State</dt>
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
            <dt class="text-gray-500">Listener</dt>
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

        <x-detail-row label="Last seen" class="text-xs">{{ $lastSeen ? \Carbon\Carbon::parse($lastSeen)->diffForHumans() : '—' }}</x-detail-row>
    </dl>

    @if($np)
        <div class="border-t border-gray-100 dark:border-stone-800 pt-5 space-y-3">

            <p class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Now Playing</p>

            <dl class="space-y-2.5 text-sm">

                {{-- Platform / type --}}
                @if($np->platform || $np->type)
                    <x-detail-row label="Platform" class="font-mono text-xs text-gray-600 dark:text-gray-400 font-normal">{{ $np->platform }}{{ $np->type ? ' / ' . $np->type : '' }}</x-detail-row>
                @endif

                {{-- Source --}}
                @if($np->source)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Source</dt>
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
                    <x-detail-row label="Radio" class="text-xs">{{ $np->radio->name }}</x-detail-row>
                @endif

                {{-- Track --}}
                @if($np->track)
                    <x-detail-row label="Track" class="text-xs max-w-48 truncate">{{ $np->track->name ?? '—' }}</x-detail-row>
                    @if($np->track->artist)
                        <x-detail-row label="Artist" class="text-xs max-w-48 truncate">{{ $np->track->artist->name }}</x-detail-row>
                    @endif
                    @if(($np->track->duration ?? 0) > 0)
                        <x-detail-row label="Duration" class="font-mono text-xs text-gray-600 dark:text-gray-400 font-normal">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($np->track->duration) }}</x-detail-row>
                    @endif
                @endif

                {{-- Album --}}
                @if($np->album)
                    <x-detail-row label="Album" class="text-xs max-w-48 truncate">{{ $np->album->name }}</x-detail-row>
                @endif

                {{-- Position --}}
                @if($np->position > 0)
                    <x-detail-row label="Position" class="font-mono text-xs text-gray-600 dark:text-gray-400 font-normal">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($np->position) }}</x-detail-row>
                @endif

            </dl>
        </div>
    @else
        <div class="border-t border-gray-100 dark:border-stone-800 pt-4">
            <p class="text-xs text-gray-400 dark:text-gray-600 italic">No now_playing in cache</p>
        </div>
    @endif

</x-card>
