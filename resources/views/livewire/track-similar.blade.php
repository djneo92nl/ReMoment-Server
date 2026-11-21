<div data-test="track-similar" class="text-gray-500 dark:text-gray-400">
    @if(!$analysed)
        <span class="text-xs" data-test="similar-none">No sound data for this track yet.</span>
    @elseif($rows === [])
        <span class="text-xs" data-test="similar-empty">Nothing in this library sounds like it.</span>
    @else
        <div class="mb-2 flex items-center justify-between gap-4">
            @if($title !== '')<h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $title }}</h3>@endif
            <button type="button" wire:click="radio" data-test="similar-radio" title="Play this track, then what sounds most like it, on the pinned player"
                    class="rounded-md border border-gray-300 px-2 py-0.5 text-[11px] hover:bg-gray-100 dark:border-stone-600 dark:hover:bg-stone-800"><i class="fa-solid fa-tower-broadcast mr-1"></i>Play similar</button>
        </div>
        @if($notice)<p class="mb-2 text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</p>@endif
        <div class="grid gap-x-4 gap-y-1 sm:grid-cols-2" data-test="similar-rows">
            @foreach($rows as $row)
                <div class="group flex items-center gap-3 rounded-2xl px-2 py-1.5 hover:bg-gray-100 dark:hover:bg-stone-900" data-test="similar-{{ $row['id'] }}">
                    <x-artwork-thumb :src="$row['image']" :colors="$row['colors']" :seed="$row['title']" icon="fa-solid fa-music" size="h-10 w-10" proxy="120" class="shrink-0" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" title="{{ $row['title'] }}">{{ $row['title'] }}</p>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $row['artist'] }}</p>
                    </div>
                    <span class="text-[11px] tabular-nums text-gray-400 dark:text-gray-600" title="How alike the sound is">{{ $row['similarity'] }}%</span>
                    <button type="button" wire:click="play({{ $row['id'] }})" aria-label="Play {{ $row['title'] }}" title="Play on the pinned player"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-gray-900 transition hover:bg-gray-200 dark:text-gray-100 dark:hover:bg-stone-800"><i class="fa-solid fa-play text-xs"></i></button>
                </div>
            @endforeach
        </div>
    @endif
</div>
