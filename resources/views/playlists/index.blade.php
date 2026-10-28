<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Playlists" :subtitle="$source === 'streaming' ? 'Your playlists on Spotify' : number_format($playlists->count()).' playlists'">
            <x-slot name="actions">
                <div class="inline-flex rounded-xl border border-gray-200 bg-white p-0.5 text-sm dark:border-stone-700 dark:bg-stone-900" role="group" aria-label="Playlists">
                    @foreach(['local' => 'Local', 'streaming' => 'Streaming'] as $key => $label)
                        <a href="{{ route('playlists.index', $key === 'local' ? [] : ['source' => $key]) }}" data-test="filter-{{ $key }}"
                           @class(['rounded-[10px] px-3 py-1.5 transition-colors', 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' => $source === $key, 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $source !== $key])>{{ $label }}</a>
                    @endforeach
                </div>
                <x-secondary-button type="button" @click="$dispatch('open-modal', 'create-smart-playlist')">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span class="hidden sm:inline">New smart playlist</span>
                </x-secondary-button>
                <x-primary-button type="button" @click="$dispatch('open-modal', 'create-playlist')">
                    <i class="fa-solid fa-plus"></i>
                    <span class="hidden sm:inline">New playlist</span>
                </x-primary-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @if($source === 'streaming')
        <livewire:external-playlists />
    @elseif($playlists->isEmpty())
        <x-empty-state icon="list-ul" text="No playlists yet. Create one, or open the Streaming side for your Spotify playlists." />
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($playlists as $playlist)
                <x-card compact :href="route('playlists.show', $playlist)" class="p-5 group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-stone-800 flex items-center justify-center flex-shrink-0 overflow-hidden group-hover:bg-gray-200 dark:group-hover:bg-stone-700 transition-colors">
                            @if($cover = \App\Domain\Artwork\LibraryItemArtwork::proxy(\App\Domain\Artwork\LibraryArtwork::coverUrl($playlist->images)))
                                <img src="{{ $cover }}" alt="{{ $playlist->name }}" class="w-full h-full object-cover">
                            @else
                                <i class="fa-solid fa-list-ul text-gray-400 dark:text-gray-500"></i>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $playlist->name }}</p>
                                <x-source-icon :source="$playlist->source" />
                            </div>
                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">
                                {{ number_format($playlist->tracks_count) }} {{ Str::plural('track', $playlist->tracks_count) }}
                            </p>
                        </div>
                        <i class="fa-solid fa-chevron-right text-gray-200 dark:text-stone-700 text-xs group-hover:text-gray-400 dark:group-hover:text-stone-500 transition-colors"></i>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif

    <x-modal name="create-playlist" maxWidth="sm">
        <div class="bg-white dark:bg-stone-900 rounded-2xl overflow-hidden">
            <div class="flex items-start justify-between px-6 pt-6 pb-4 border-b border-gray-100 dark:border-stone-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">New playlist</h3>
                <button type="button" @click="$dispatch('close-modal', 'create-playlist')"
                        class="ml-4 flex items-center justify-center w-7 h-7 rounded-xl text-gray-400 dark:text-gray-600 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-600 dark:hover:text-gray-300 transition-colors flex-shrink-0">
                    <i class="fa-solid fa-xmark text-xs"></i>
                </button>
            </div>
            <form method="POST" action="{{ route('playlists.store') }}" class="p-6 space-y-4">
                @csrf
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" required autofocus />
                </div>
                <x-primary-button size="md" class="w-full justify-center">Create</x-primary-button>
            </form>
        </div>
    </x-modal>

    <x-modal name="create-smart-playlist" maxWidth="sm">
        <div class="bg-white dark:bg-stone-900 rounded-2xl overflow-hidden">
            <div class="flex items-start justify-between px-6 pt-6 pb-4 border-b border-gray-100 dark:border-stone-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">New smart playlist</h3>
                <button type="button" @click="$dispatch('close-modal', 'create-smart-playlist')"
                        class="ml-4 flex items-center justify-center w-7 h-7 rounded-xl text-gray-400 dark:text-gray-600 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-600 dark:hover:text-gray-300 transition-colors flex-shrink-0">
                    <i class="fa-solid fa-xmark text-xs"></i>
                </button>
            </div>
            <form method="POST" action="{{ route('playlists.store') }}" class="p-6 space-y-4">
                @csrf
                <input type="hidden" name="smart" value="1">
                <div>
                    <x-input-label for="smart-name" value="Name" />
                    <x-text-input id="smart-name" name="name" type="text" class="mt-1 block w-full" required autofocus />
                </div>
                <x-primary-button size="md" class="w-full justify-center">Create</x-primary-button>
            </form>
        </div>
    </x-modal>
</x-app-layout>
