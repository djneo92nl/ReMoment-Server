<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back-button href="{{ route('settings.index') }}" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Spotify Library</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Import saved tracks and playlists from your Spotify account</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl space-y-6">

        @if(session('success'))
            <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-2xl px-6 py-4 text-sm text-emerald-800 dark:text-emerald-300">
                <i class="fa-solid fa-circle-check mr-2"></i>{{ session('success') }}
            </div>
        @endif

        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-2xl px-6 py-4 text-sm text-blue-800 dark:text-blue-300">
            <i class="fa-solid fa-circle-info mr-2"></i>
            Sync your saved tracks and playlists into the shared library. This also runs automatically once a day. You can
            trigger it manually from the command line with
            <code class="font-mono bg-blue-100 dark:bg-blue-900 px-1 rounded">php artisan library:sync-spotify</code>.
        </div>

        @if(!$connected)
            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 flex flex-col items-center justify-center py-20 text-center px-8">
                <i class="fa-brands fa-spotify text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
                <p class="text-gray-500 dark:text-gray-500">Spotify is not connected yet.</p>
                <a href="{{ route('settings.index') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline mt-1">
                    Connect Spotify from Settings →
                </a>
            </div>
        @else
            @if(!$hasRequiredScopes)
                <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-2xl px-6 py-4 text-sm text-amber-800 dark:text-amber-300 flex items-center justify-between gap-4">
                    <span><i class="fa-solid fa-triangle-exclamation mr-2"></i>Reconnect Spotify to grant library access permissions.</span>
                    <a href="{{ route('spotify.authorize') }}"
                       class="shrink-0 px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white text-xs font-medium rounded-xl transition-colors">
                        Reconnect
                    </a>
                </div>
            @else
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">
                    <div class="grid grid-cols-12 gap-4 items-center px-8 py-5 border-b border-gray-50 dark:border-stone-800/50">
                        <div class="col-span-6">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Saved Tracks</p>
                            @if($tracksSyncedAt)
                                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Last synced {{ \Illuminate\Support\Carbon::parse($tracksSyncedAt)->diffForHumans() }}</p>
                            @else
                                <p class="text-xs text-gray-300 dark:text-stone-600 mt-0.5">Never synced</p>
                            @endif
                        </div>
                        <div class="col-span-6 flex justify-end">
                            <form method="POST" action="{{ route('settings.spotify-library.sync-tracks') }}">
                                @csrf
                                <button type="submit"
                                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 rounded-xl transition-colors">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                    Sync
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="grid grid-cols-12 gap-4 items-center px-8 py-5">
                        <div class="col-span-6">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Playlists</p>
                            @if($playlistsSyncedAt)
                                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">Last synced {{ \Illuminate\Support\Carbon::parse($playlistsSyncedAt)->diffForHumans() }}</p>
                            @else
                                <p class="text-xs text-gray-300 dark:text-stone-600 mt-0.5">Never synced</p>
                            @endif
                        </div>
                        <div class="col-span-6 flex justify-end">
                            <form method="POST" action="{{ route('settings.spotify-library.sync-playlists') }}">
                                @csrf
                                <button type="submit"
                                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 rounded-xl transition-colors">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                    Sync
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-app-layout>
