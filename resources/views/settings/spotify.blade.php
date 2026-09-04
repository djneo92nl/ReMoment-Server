<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back-button href="{{ route('settings.index') }}" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Spotify</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Connect your Spotify account to track playback</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl space-y-6">

        @if(session('success'))
            <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-2xl px-6 py-4 text-sm text-emerald-800 dark:text-emerald-300">
                <i class="fa-solid fa-circle-check mr-2"></i>{{ session('success') }}
            </div>
        @endif

        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-12 h-12 bg-emerald-50 dark:bg-emerald-900/20 rounded-2xl flex items-center justify-center">
                    <i class="fa-brands fa-spotify text-emerald-500 text-xl"></i>
                </div>
                @if($connected)
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>Connected
                    </span>
                @endif
            </div>

            @if($connected)
                <p class="text-sm text-gray-500 dark:text-gray-500 mb-6">
                    Playback is tracked via the Spotify Web API. Map a Spotify Connect speaker to a local device below
                    so playback is attributed correctly, or sync your saved library.
                </p>
                <form method="POST" action="{{ route('spotify.disconnect') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">
                        Disconnect
                    </button>
                </form>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-500 mb-6">
                    Connect your Spotify account to track playback.
                </p>
                <a href="{{ route('spotify.authorize') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors">
                    <i class="fa-brands fa-spotify"></i> Connect Spotify
                </a>
            @endif
        </div>

        @if($connected)
            <x-settings-group>
                <x-settings-row href="{{ route('settings.spotify-connect') }}"
                                 icon="fa-solid fa-tower-broadcast" icon-bg="bg-emerald-50 dark:bg-emerald-900/20" icon-color="text-emerald-500"
                                 label="Speaker Mappings" />
                <x-settings-row href="{{ route('settings.spotify-library') }}"
                                 icon="fa-solid fa-book" icon-bg="bg-emerald-50 dark:bg-emerald-900/20" icon-color="text-emerald-500"
                                 label="Library Sync" />
            </x-settings-group>
        @endif
    </div>
</x-app-layout>
