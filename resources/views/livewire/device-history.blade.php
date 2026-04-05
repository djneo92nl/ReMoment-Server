<div wire:poll.10s>
    @if($plays->isNotEmpty())
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">

            <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Recently Played</h2>

            {{-- Horizontal scrollable track list --}}
            <div class="flex gap-4 overflow-x-auto pb-2 scrollbar-none"
                 style="-ms-overflow-style:none; scrollbar-width:none;">

                @foreach($plays as $play)
                    @php
                        $track = $play->track;
                        $artUrl = $track->images[0]['url']
                            ?? $track->album?->images[0]['url']
                            ?? null;
                    @endphp

                    <div class="flex-shrink-0 w-28 group cursor-default">

                        {{-- Album art --}}
                        <div class="w-28 h-28 rounded-2xl overflow-hidden shadow-md ring-1 ring-gray-100 dark:ring-stone-800 mb-3 bg-gray-100 dark:bg-stone-800 relative">
                            @if($artUrl)
                                <img src="{{ $artUrl }}"
                                     alt="{{ $track->name }}"
                                     class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <i class="fa-solid fa-music text-2xl text-gray-300 dark:text-stone-600"></i>
                                </div>
                            @endif

                            {{-- Subtle time badge --}}
                            <div class="absolute bottom-1.5 right-1.5 bg-black/50 rounded-md px-1.5 py-0.5 text-white text-xs leading-none">
                                {{ $play->played_at->format('H:i') }}
                            </div>
                        </div>

                        {{-- Track info --}}
                        <p class="text-xs font-medium text-gray-900 dark:text-gray-100 truncate leading-snug"
                           title="{{ $track->name }}">{{ $track->name }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-600 truncate mt-0.5 leading-snug"
                           title="{{ $track->artist?->name }}">{{ $track->artist?->name }}</p>
                    </div>
                @endforeach

            </div>
        </div>
    @endif
</div>
