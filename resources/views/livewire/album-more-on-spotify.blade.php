<div wire:init="load">
    @if($available && $loaded && !$failed && ($rows === null || $missing > 0 || $message))
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
            <div class="flex flex-wrap items-center justify-between gap-3 {{ $missing > 0 ? 'mb-4' : '' }}">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">
                    <i class="fa-brands fa-spotify text-emerald-500 mr-1.5"></i>More on Spotify
                </h2>
                @if($missing > 0 && !$message)
                    <button wire:click="addAll" wire:loading.attr="disabled" type="button"
                            class="px-3 py-1 rounded-full bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-xs text-gray-600 dark:text-gray-400 transition-colors disabled:opacity-60">
                        <span wire:loading.remove wire:target="addAll">Add {{ $missing }} {{ Str::plural('track', $missing) }} to album</span>
                        <span style="display:none" wire:loading wire:target="addAll">Adding…</span>
                    </button>
                @endif
            </div>

            @if($message)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
            @elseif($rows === null)
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-600">This album isn't on Spotify.</p>
            @else
                <div class="divide-y divide-gray-50 dark:divide-stone-800/50">
                    @foreach($rows as $row)
                        <div class="flex items-center gap-4 py-2 {{ $row['local'] ? 'opacity-40' : '' }}">
                            <span class="w-5 text-center text-xs text-gray-400 dark:text-stone-500 flex-shrink-0">{{ $row['number'] ?: '' }}</span>
                            <p class="flex-1 min-w-0 text-sm text-gray-700 dark:text-gray-300 truncate">
                                {{ $row['name'] }}
                                @if($row['local'])
                                    <span class="ml-1.5 text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500">In album</span>
                                @endif
                            </p>
                            @if($row['duration'])
                                <span class="text-xs text-gray-400 dark:text-gray-600 flex-shrink-0">{{ gmdate('g:i', $row['duration']) }}</span>
                            @endif
                            @php
                                // A track we have plays from the library when it can, else by its Spotify id like the others.
                                $viaLibrary = $row['local'] && \App\Domain\Library\LibraryPlayback::trackPlayable($row['local']);
                                $rowDevices = $viaLibrary
                                    ? $devices->filter(fn ($d) => \App\Domain\Library\LibraryPlayback::trackPlayable($row['local'], $d))->values()
                                    : $devices->filter(fn ($d) => \App\Domain\Library\LibraryPlayback::canPlaySpotify($d))->values();
                            @endphp
                            @if($rowDevices->isNotEmpty())
                                <x-play-button
                                    name="spotify-row-{{ $row['id'] }}"
                                    title="Play track"
                                    :description="$row['name']"
                                    :devices="$rowDevices"
                                    :action-template="$viaLibrary ? \App\Support\DeviceAction::template('tracks.play', ['track' => $row['local']]) : \App\Support\DeviceAction::template('spotify.tracks.play', ['spotifyTrackId' => $row['id']])"
                                    :elsewhere="false"
                                    class="w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-stone-700 flex-shrink-0">
                                    <i class="fa-solid fa-play text-xs"></i>
                                </x-play-button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
