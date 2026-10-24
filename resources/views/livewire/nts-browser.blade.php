<div class="space-y-4">
    @if($show !== null)
        <button type="button" wire:click="back" class="text-sm text-gray-500 dark:text-gray-400 hover:underline"><i class="fa-solid fa-arrow-left mr-1"></i>Back</button>
    @endif

    @if($show === null)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @forelse($shows as $s)
                <x-card compact class="p-4 cursor-pointer" wire:key="show-{{ $s['alias'] }}" wire:click="openShow('{{ $s['alias'] }}')">
                    <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $s['name'] }}</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 truncate">{{ collect([implode(', ', $s['genres']), $s['location']])->filter()->implode(' · ') }}</p>
                    @if($s['description'])<p class="text-xs text-gray-500 dark:text-gray-400 mt-2 line-clamp-3">{{ $s['description'] }}</p>@endif
                </x-card>
            @empty
                <p class="text-sm text-gray-400 dark:text-gray-600">NTS did not answer.</p>
            @endforelse
        </div>
        <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
            <x-secondary-button type="button" wire:click="previous" :disabled="$page === 0">Previous</x-secondary-button>
            <span>{{ $page * $perPage + 1 }}–{{ min(($page + 1) * $perPage, max($total, 1)) }} of {{ $total }}</span>
            <x-secondary-button type="button" wire:click="next" :disabled="($page + 1) * $perPage >= $total">Next</x-secondary-button>
        </div>
    @elseif($episode === null)
        <x-card class="p-5">
            @forelse($episodes as $e)
                <button type="button" wire:key="ep-{{ $e['alias'] }}" wire:click="openEpisode('{{ $e['alias'] }}')"
                        class="w-full flex items-center justify-between gap-3 py-2 border-t border-gray-100 dark:border-stone-800 first:border-0 text-left">
                    <span class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ $e['name'] }}</span>
                    <span class="text-xs text-gray-400 dark:text-gray-600 flex-shrink-0">{{ $e['broadcast'] ? \Illuminate\Support\Carbon::parse($e['broadcast'])->format('j M Y') : '' }}</span>
                </button>
            @empty
                <p class="text-sm text-gray-400 dark:text-gray-600">No episodes found.</p>
            @endforelse
        </x-card>
    @else
        <x-card class="p-5">
            <div class="flex items-start justify-between gap-3 mb-3">
                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $episodeInfo['name'] ?? $episode }}</p>
                <div class="flex gap-3 text-xs flex-shrink-0">
                    @if($episodeInfo['mixcloud'] ?? null)<a href="{{ $episodeInfo['mixcloud'] }}" target="_blank" rel="noopener" class="text-gray-500 hover:underline">Mixcloud</a>@endif
                    @if($episodeInfo['soundcloud'] ?? null)<a href="{{ $episodeInfo['soundcloud'] }}" target="_blank" rel="noopener" class="text-gray-500 hover:underline">SoundCloud</a>@endif
                </div>
            </div>
            @forelse($tracklist as $t)
                <div class="flex items-center gap-3 py-2 border-t border-gray-100 dark:border-stone-800 first:border-0">
                    <span class="text-xs text-gray-400 dark:text-gray-600 w-12 flex-shrink-0">{{ $t['offset'] !== null ? sprintf('%d:%02d', intdiv($t['offset'], 60), $t['offset'] % 60) : '' }}</span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ $t['title'] }}</p>
                        @if($t['artist'])<p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $t['artist'] }}</p>@endif
                    </div>
                    @if($t['album_id'])
                        <a href="{{ route('albums.show', $t['album_id']) }}" class="text-xs text-emerald-600 dark:text-emerald-400 flex-shrink-0"><i class="fa-solid fa-check mr-1"></i>In library</a>
                    @elseif($t['artist'])
                        <a href="https://open.spotify.com/search/{{ rawurlencode($t['artist'].' '.$t['title']) }}" target="_blank" rel="noopener" class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 flex-shrink-0">Find</a>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-400 dark:text-gray-600">No tracklist published for this episode (NTS adds them some hours after the broadcast, not for every show).</p>
            @endforelse
        </x-card>
    @endif
</div>
