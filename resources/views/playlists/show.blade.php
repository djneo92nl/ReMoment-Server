<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$playlist->name"
                       :subtitle="number_format($playlist->tracks->count()).' '.Str::plural('track', $playlist->tracks->count()).($playlist->source === 'spotify' && $playlist->spotifyOwner() ? ' · by '.$playlist->spotifyOwner() : '')"
                       :back="route('playlists.index')">
            <x-slot name="badge"><x-source-icon :source="$playlist->source" size="text-lg" /></x-slot>
            @if($playlist->isSmart() || ! $playlist->isEditable())
                <x-slot name="actions">
                    <p class="text-xs text-gray-400 dark:text-gray-600">
                        @if($playlist->isSmart())
                            <i class="fa-solid fa-wand-magic-sparkles mr-1"></i>Smart playlist
                        @else
                            <i class="fa-solid fa-lock mr-1"></i>Synced from Spotify
                        @endif
                    </p>
                </x-slot>
            @endif
        </x-page-header>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            @if($playlist->isEditable())
                <livewire:playlist-manager :playlist="$playlist" />
            @else
                @if($playlist->isSmart())
                    <livewire:smart-playlist-editor :playlist="$playlist" />
                @endif
                <x-card flush>
                    @if($playlist->tracks->isEmpty())
                        <x-empty-state bare icon="music" text="No tracks in this playlist yet." />
                    @else
                        <div class="divide-y divide-gray-50 dark:divide-stone-800/50">
                            @foreach($playlist->tracks as $i => $track)
                                <div class="flex items-center gap-4 px-6 py-3 hover:bg-gray-50 dark:hover:bg-stone-800/30 transition-colors group">
                                    <span class="w-5 text-center text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">{{ $i + 1 }}</span>
                                    <x-source-icon :source="$track->source" class="flex-shrink-0" />
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ $track->name }}</p>
                                        <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 truncate">
                                            {{ $track->artist?->name }}
                                            @if($track->duration)
                                                &middot; {{ \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $track->duration) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-card>
            @endif
        </div>

        <div class="space-y-6">
            @php($devices = $playlist->source === 'spotify' ? $spotifyDevices : $playableDevices)

            <x-card class="p-6 md:p-8">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Playback</h2>

                @if($playlist->tracks->isEmpty())
                    <p class="text-xs text-gray-400 dark:text-gray-600">
                        {{ $playlist->isSmart() ? 'No tracks match the rules yet.' : 'Add tracks before playing this playlist.' }}
                    </p>
                @elseif($devices->isEmpty())
                    <p class="text-xs text-gray-400 dark:text-gray-600">
                        @if($playlist->source === 'spotify')
                            No devices are mapped to a Spotify Connect speaker yet. Configure this in
                            <a href="{{ route('settings.spotify-connect') }}" class="text-gray-900 dark:text-gray-100 underline">Settings &rarr; Spotify Connect</a>.
                        @else
                            No compatible devices available.
                        @endif
                    </p>
                @else
                    <x-play-button
                        name="play-playlist-{{ $playlist->id }}"
                        title="Play on device"
                        :description="$playlist->name"
                        :devices="$devices"
                        :action-template="\App\Support\DeviceAction::template('playlists.play', ['playlist' => $playlist])"
                        wrapper-class="w-full"
                        class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gray-900 dark:bg-stone-700 hover:bg-gray-700 dark:hover:bg-stone-600 text-white text-sm font-medium transition-colors">
                        <i class="fa-solid fa-play text-xs"></i>
                        Play
                    </x-play-button>
                @endif
            </x-card>

            @if($playlist->isDeletable())
                <form method="POST" action="{{ route('playlists.destroy', $playlist) }}"
                      onsubmit="return confirm('Delete this playlist? This cannot be undone.')">
                    @csrf @method('DELETE')
                    <x-danger-button class="w-full justify-center"><i class="fa-solid fa-trash text-xs"></i>Delete playlist</x-danger-button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
