<div wire:init="load">
    @if(!$available)
        <p class="px-5 py-6 text-sm text-gray-400 dark:text-gray-600">Connect Spotify to see this album there.</p>
    @elseif(!$loaded)
        <p class="px-5 py-6 text-sm text-gray-400 dark:text-gray-600"><i class="fa-brands fa-spotify mr-1.5 text-emerald-500"></i>Loading from Spotify…</p>
    @elseif($failed)
        <p class="px-5 py-6 text-sm text-gray-500">Spotify did not answer. Try again in a moment.</p>
    @elseif($rows === null)
        <p class="px-5 py-6 text-sm text-gray-400 dark:text-gray-600">This album isn't on Spotify.</p>
    @else
        @if($missing > 0 || $message)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-2.5 dark:border-stone-800/70">
                <span class="text-xs text-gray-500">{{ $message ?? $missing.' '.Str::plural('track', $missing).' not in your library yet' }}</span>
                @if($missing > 0 && !$message)
                    <button wire:click="addAll" wire:loading.attr="disabled" type="button"
                            class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600 transition-colors hover:bg-gray-200 disabled:opacity-60 dark:bg-stone-800 dark:text-gray-400 dark:hover:bg-stone-700">
                        <span wire:loading.remove wire:target="addAll">Add {{ $missing }} to album</span>
                        <span style="display:none" wire:loading wire:target="addAll">Adding…</span>
                    </button>
                @endif
            </div>
        @endif
        <div class="divide-y divide-gray-100 dark:divide-stone-800/70">
            @foreach($rows as $row)
                <div class="group flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-gray-50 sm:px-5 dark:hover:bg-stone-800/30">
                    <div class="relative h-8 w-8 flex-none">
                        <span class="absolute inset-0 flex items-center justify-center text-sm tabular-nums text-gray-400 dark:text-stone-500 {{ $canPlay ? 'group-hover:hidden group-focus-within:hidden [@media(hover:none)]:hidden' : '' }}">{{ $row['number'] ?: '' }}</span>
                        @if($canPlay)
                            @if($pinned)
                                <form method="POST" action="{{ str_replace('{id}', $pinned->id, \App\Support\DeviceAction::template('spotify.tracks.play', ['spotifyTrackId' => $row['id']])) }}" class="hidden group-hover:block group-focus-within:block [@media(hover:none)]:block">
                                    @csrf
                                    <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-full text-gray-900 hover:bg-gray-200 dark:text-gray-100 dark:hover:bg-stone-700" title="Play on {{ $pinned->device_name }}" aria-label="Play {{ $row['name'] }} on {{ $pinned->device_name }}"><i class="fa-solid fa-play text-xs"></i></button>
                                </form>
                            @else
                                <button type="button" class="hidden h-8 w-8 items-center justify-center rounded-full text-gray-900 hover:bg-gray-200 group-hover:flex group-focus-within:flex dark:text-gray-100 dark:hover:bg-stone-700 [@media(hover:none)]:flex" @click="$dispatch('open-player-panel')" title="Choose a player to play on" aria-label="Choose a player to play {{ $row['name'] }} on"><i class="fa-solid fa-play text-xs"></i></button>
                            @endif
                        @endif
                    </div>
                    <p class="flex min-w-0 flex-1 items-center gap-2 text-sm text-gray-900 dark:text-gray-100">
                        <span class="truncate">{{ $row['name'] }}</span>
                        @if($row['local'])
                            <i class="fa-solid fa-circle-check flex-none text-[11px] text-gray-400 dark:text-gray-600" title="In your library"></i>
                        @endif
                    </p>
                    <span class="w-10 flex-none text-right text-xs tabular-nums text-gray-400 dark:text-stone-500">{{ $row['duration'] ? \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $row['duration']) : '' }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
