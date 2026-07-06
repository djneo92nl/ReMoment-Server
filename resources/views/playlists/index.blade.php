<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Playlists</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">{{ number_format($playlists->count()) }} playlists</p>
            </div>

            <button type="button"
                    @click="$dispatch('open-modal', 'create-playlist')"
                    class="flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl text-sm font-medium transition-colors">
                <i class="fa-solid fa-plus"></i>
                <span class="hidden sm:inline">New playlist</span>
            </button>
        </div>
    </x-slot>

    @if($playlists->isEmpty())
        <div class="bg-white dark:bg-stone-900 rounded-3xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-16 text-center">
            <i class="fa-solid fa-list-ul text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
            <p class="text-gray-400 dark:text-gray-600 text-sm">No playlists yet — create one or sync from Spotify</p>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($playlists as $playlist)
                <a href="{{ route('playlists.show', $playlist) }}"
                   class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-5 hover:border-gray-300 dark:hover:border-stone-700 hover:shadow-md transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-stone-800 flex items-center justify-center flex-shrink-0 overflow-hidden group-hover:bg-gray-200 dark:group-hover:bg-stone-700 transition-colors">
                            @if($playlist->images[0]['url'] ?? null)
                                <img src="{{ $playlist->images[0]['url'] }}" alt="{{ $playlist->name }}" class="w-full h-full object-cover">
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
                </a>
            @endforeach
        </div>
    @endif

    <x-modal name="create-playlist" maxWidth="sm">
        <div class="bg-white dark:bg-stone-900 rounded-lg overflow-hidden">
            <div class="flex items-start justify-between px-6 pt-6 pb-4 border-b border-gray-100 dark:border-stone-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">New playlist</h3>
                <button type="button" @click="$dispatch('close-modal', 'create-playlist')"
                        class="ml-4 flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 dark:text-gray-600 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-600 dark:hover:text-gray-300 transition-colors flex-shrink-0">
                    <i class="fa-solid fa-xmark text-xs"></i>
                </button>
            </div>
            <form method="POST" action="{{ route('playlists.store') }}" class="p-6 space-y-4">
                @csrf
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" required autofocus />
                </div>
                <button type="submit"
                        class="w-full px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-medium transition-colors">
                    Create
                </button>
            </form>
        </div>
    </x-modal>
</x-app-layout>
