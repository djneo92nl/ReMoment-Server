<div>
    {{-- Filter bar --}}
    <div class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-4 mb-6 flex flex-wrap gap-3 items-center">

        {{-- Device filter --}}
        <select wire:model.live="deviceId"
                class="text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 text-gray-700 dark:text-gray-300 rounded-xl px-3 py-2 pr-8 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600 appearance-none cursor-pointer">
            <option value="">All devices</option>
            @foreach($devices as $device)
                <option value="{{ $device->id }}">{{ $device->device_name }}</option>
            @endforeach
        </select>

        {{-- Source type filter pills --}}
        <div class="flex flex-wrap gap-2">
            <button wire:click="setSource(null)"
                    class="text-xs px-3 py-1.5 rounded-full transition-colors {{ $sourceFilter === null ? 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 font-medium' : 'bg-gray-100 dark:bg-stone-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-stone-700' }}">
                All
            </button>
            @foreach($sourceTypes as $type)
                @php
                    $label = match(strtolower($type)) {
                        'radio'   => 'Radio',
                        'spotify' => 'Spotify',
                        'tidal'   => 'Tidal',
                        'deezer'  => 'Deezer',
                        'music'   => 'Music',
                        'source'  => 'Line In',
                        'media'   => 'Media',
                        'video'   => 'Video',
                        default   => ucfirst($type),
                    };
                    $icon = match(strtolower($type)) {
                        'radio'   => 'fa-tower-broadcast',
                        'spotify' => 'fa-brands fa-spotify',
                        'tidal'   => 'fa-music',
                        'deezer'  => 'fa-music',
                        'source'  => 'fa-plug',
                        'video'   => 'fa-film',
                        default   => 'fa-music',
                    };
                @endphp
                <button wire:click="setSource('{{ $type }}')"
                        class="text-xs px-3 py-1.5 rounded-full transition-colors flex items-center gap-1.5 {{ $sourceFilter === $type ? 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 font-medium' : 'bg-gray-100 dark:bg-stone-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-stone-700' }}">
                    <i class="fa-solid {{ $icon }} text-[10px]"></i>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if($plays->total() > 0)
            <span class="ml-auto text-xs text-gray-400 dark:text-gray-600">
                {{ number_format($plays->total()) }} {{ Str::plural('play', $plays->total()) }}
            </span>
        @endif
    </div>

    @if($plays->isEmpty())
        <div class="bg-white dark:bg-stone-900 rounded-3xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-16 text-center">
            <i class="fa-solid fa-clock-rotate-left text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
            <p class="text-gray-400 dark:text-gray-600 text-sm">No history yet</p>
        </div>
    @else
        {{-- Group plays by day --}}
        @php
            $grouped = $plays->getCollection()->groupBy(fn($play) => $play->played_at->format('Y-m-d'));
        @endphp

        <div class="space-y-8">
            @foreach($grouped as $date => $dayPlays)
                @php
                    $dateObj = \Carbon\Carbon::parse($date);
                    $dateLabel = $dateObj->isToday()
                        ? 'Today'
                        : ($dateObj->isYesterday() ? 'Yesterday' : $dateObj->format('l, F j, Y'));
                @endphp

                <div>
                    {{-- Day heading --}}
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-xs font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-600">
                            {{ $dateLabel }}
                        </span>
                        <div class="flex-1 h-px bg-gray-100 dark:bg-stone-800"></div>
                        <span class="text-xs text-gray-300 dark:text-stone-700">{{ $dayPlays->count() }} {{ Str::plural('play', $dayPlays->count()) }}</span>
                    </div>

                    {{-- Timeline entries --}}
                    <div class="relative">
                        {{-- Vertical line --}}
                        <div class="absolute left-[5.5rem] top-0 bottom-0 w-px bg-gray-100 dark:bg-stone-800/80"></div>

                        <div class="space-y-2">
                            @foreach($dayPlays as $play)
                                @php
                                    $sourceType = strtolower($play->source_type ?? 'music');

                                    // Dot color
                                    $dotColor = match($sourceType) {
                                        'radio'   => 'bg-sky-400 dark:bg-sky-500',
                                        'spotify' => 'bg-emerald-400 dark:bg-emerald-500',
                                        'tidal'   => 'bg-violet-400 dark:bg-violet-500',
                                        'deezer'  => 'bg-orange-400 dark:bg-orange-500',
                                        'source'  => 'bg-amber-400 dark:bg-amber-500',
                                        'video'   => 'bg-rose-400 dark:bg-rose-500',
                                        default   => 'bg-gray-300 dark:bg-stone-600',
                                    };

                                    // Icon
                                    $icon = match($sourceType) {
                                        'radio'   => 'fa-tower-broadcast text-sky-500 dark:text-sky-400',
                                        'spotify' => 'fa-brands fa-spotify text-emerald-500',
                                        'tidal'   => 'fa-music text-violet-500',
                                        'source'  => 'fa-plug text-amber-500',
                                        'video'   => 'fa-film text-rose-500',
                                        default   => 'fa-music text-gray-400 dark:text-stone-500',
                                    };

                                    // Art URL (tracks only)
                                    $artUrl = null;
                                    if ($play->track) {
                                        $artUrl = $play->track->images[0]['url']
                                            ?? $play->track->album?->images[0]['url']
                                            ?? null;
                                    }
                                @endphp

                                <div class="flex items-start gap-0">

                                    {{-- Time column --}}
                                    <div class="w-20 flex-shrink-0 pt-3 text-right pr-3">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-500">
                                            {{ $play->played_at->format('H:i') }}
                                        </span>
                                        @if($play->ended_at)
                                            <div class="text-[10px] text-gray-300 dark:text-stone-600 leading-tight">
                                                – {{ $play->ended_at->format('H:i') }}
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Dot --}}
                                    <div class="flex-shrink-0 relative z-10 mt-3.5">
                                        <div class="w-3 h-3 rounded-full {{ $dotColor }} ring-2 ring-white dark:ring-stone-950"></div>
                                    </div>

                                    {{-- Content card --}}
                                    <div class="flex-1 ml-4 mb-1">
                                        <div class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-100 dark:border-stone-800 p-3 flex items-center gap-3 hover:border-gray-200 dark:hover:border-stone-700 transition-colors">

                                            @if($play->track)
                                                {{-- Track: album art --}}
                                                <div class="w-10 h-10 rounded-xl overflow-hidden bg-gray-100 dark:bg-stone-800 flex-shrink-0">
                                                    @if($artUrl)
                                                        <img src="{{ $artUrl }}" alt="" class="w-full h-full object-cover">
                                                    @else
                                                        <div class="w-full h-full flex items-center justify-center">
                                                            <i class="fa-solid {{ $icon }} text-sm"></i>
                                                        </div>
                                                    @endif
                                                </div>

                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate leading-snug">
                                                        {{ $play->track->name }}
                                                    </p>
                                                    <p class="text-xs text-gray-400 dark:text-gray-600 truncate mt-0.5 leading-snug">
                                                        {{ $play->track->artist?->name }}
                                                        @if($play->track->album?->name)
                                                            <span class="text-gray-300 dark:text-stone-700"> &middot; </span>
                                                            {{ $play->track->album->name }}
                                                        @endif
                                                    </p>
                                                    @if($play->ended_at)
                                                        <p class="text-[10px] text-gray-300 dark:text-stone-700 mt-0.5">
                                                            {{ gmdate('G:i', $play->played_at->diffInSeconds($play->ended_at)) }}
                                                        </p>
                                                    @endif
                                                </div>

                                            @elseif($play->source_type === 'radio')
                                                {{-- Radio --}}
                                                <div class="w-10 h-10 rounded-xl bg-sky-50 dark:bg-sky-950/40 flex items-center justify-center flex-shrink-0">
                                                    <i class="fa-solid fa-tower-broadcast text-sky-400 text-sm"></i>
                                                </div>

                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate leading-snug">
                                                        {{ $play->radio_name ?? 'Radio' }}
                                                    </p>
                                                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Radio</p>
                                                </div>

                                            @else
                                                {{-- Source / line-in --}}
                                                <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-950/30 flex items-center justify-center flex-shrink-0">
                                                    <i class="fa-solid {{ $icon }} text-sm"></i>
                                                </div>

                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate leading-snug">
                                                        {{ $play->source_name ?? ucfirst($play->source_type ?? 'Source') }}
                                                    </p>
                                                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">
                                                        {{ ucfirst($play->source_type ?? 'Source') }}
                                                    </p>
                                                </div>
                                            @endif

                                            {{-- Device badge --}}
                                            @if($play->device)
                                                <div class="flex-shrink-0 ml-auto pl-2">
                                                    <span class="text-[10px] bg-gray-100 dark:bg-stone-800 text-gray-400 dark:text-stone-500 px-2 py-1 rounded-lg whitespace-nowrap">
                                                        {{ $play->device->device_name }}
                                                    </span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        @if($plays->hasPages())
            <div class="mt-8 flex items-center justify-between">
                <div class="text-xs text-gray-400 dark:text-gray-600">
                    Showing {{ $plays->firstItem() }}–{{ $plays->lastItem() }} of {{ number_format($plays->total()) }}
                </div>
                <div class="flex gap-2">
                    @if($plays->onFirstPage())
                        <span class="px-4 py-2 text-sm rounded-xl bg-gray-100 dark:bg-stone-800 text-gray-300 dark:text-stone-600 cursor-not-allowed">
                            &larr; Previous
                        </span>
                    @else
                        <button wire:click="previousPage" class="px-4 py-2 text-sm rounded-xl bg-gray-100 dark:bg-stone-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-stone-700 transition-colors">
                            &larr; Previous
                        </button>
                    @endif

                    @if($plays->hasMorePages())
                        <button wire:click="nextPage" class="px-4 py-2 text-sm rounded-xl bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 hover:bg-gray-700 dark:hover:bg-gray-200 transition-colors">
                            Next &rarr;
                        </button>
                    @else
                        <span class="px-4 py-2 text-sm rounded-xl bg-gray-100 dark:bg-stone-800 text-gray-300 dark:text-stone-600 cursor-not-allowed">
                            Next &rarr;
                        </span>
                    @endif
                </div>
            </div>
        @endif
    @endif
</div>
