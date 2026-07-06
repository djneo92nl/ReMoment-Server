<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-start gap-4">
                <x-back-button href="{{ route('playlists.index') }}" class="mt-1" />
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">{{ $playlist->name }}</h1>
                        <x-source-icon :source="$playlist->source" size="text-lg" />
                    </div>
                    <p class="mt-1.5 text-gray-500 dark:text-gray-500">
                        {{ number_format($playlist->tracks->count()) }} {{ Str::plural('track', $playlist->tracks->count()) }}
                        @if($playlist->source === 'spotify' && $playlist->spotifyOwner())
                            &middot; by {{ $playlist->spotifyOwner() }}
                        @endif
                    </p>
                </div>
            </div>

            @if(!$playlist->isEditable())
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-2 flex-shrink-0">
                    <i class="fa-solid fa-lock mr-1"></i>Synced from Spotify
                </p>
            @endif
        </div>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            @if($playlist->isEditable())
                <livewire:playlist-manager :playlist="$playlist" />
            @else
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">
                    @if($playlist->tracks->isEmpty())
                        <div class="flex flex-col items-center justify-center py-20 text-center px-8">
                            <i class="fa-solid fa-music text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
                            <p class="text-gray-500 dark:text-gray-500">No tracks in this playlist yet.</p>
                        </div>
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
                                                &middot; {{ gmdate('g:i', $track->duration) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <div class="space-y-6">
            @php($devices = $playlist->source === 'spotify' ? $spotifyDevices : $playableDevices)

            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Playback</h2>

                @if($playlist->tracks->isEmpty())
                    <p class="text-xs text-gray-400 dark:text-gray-600">Add tracks before playing this playlist.</p>
                @elseif($devices->isEmpty())
                    <p class="text-xs text-gray-400 dark:text-gray-600">
                        @if($playlist->source === 'spotify')
                            No devices are mapped to a Spotify Connect speaker yet. Configure this in
                            <a href="{{ route('settings.spotify-connect') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">Settings &rarr; Spotify Connect</a>.
                        @else
                            No compatible devices available.
                        @endif
                    </p>
                @else
                    <button type="button"
                            @click="$dispatch('open-modal', 'play-playlist-{{ $playlist->id }}')"
                            class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition-colors">
                        <i class="fa-solid fa-play text-xs"></i>
                        Play on&hellip;
                    </button>
                    <x-device-picker
                        name="play-playlist-{{ $playlist->id }}"
                        title="Play on device"
                        :description="$playlist->name"
                        :devices="$devices"
                        :action-template="url('playlists/'.$playlist->id.'/play').'/{id}'"
                    />
                @endif
            </div>

            @if($playlist->isEditable())
                <form method="POST" action="{{ route('playlists.destroy', $playlist) }}"
                      onsubmit="return confirm('Delete this playlist? This cannot be undone.')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 text-sm font-medium hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors">
                        <i class="fa-solid fa-trash text-xs"></i>
                        Delete playlist
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
