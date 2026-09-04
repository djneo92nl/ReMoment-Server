<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back-button href="{{ route('settings.index') }}" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Last.fm</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Scrobble plays and enrich artist metadata</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl space-y-6">

        @if(session('success'))
            <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-2xl px-6 py-4 text-sm text-emerald-800 dark:text-emerald-300">
                <i class="fa-solid fa-circle-check mr-2"></i>{{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl px-6 py-4 text-sm text-red-800 dark:text-red-300">
                <i class="fa-solid fa-triangle-exclamation mr-2"></i>{{ session('error') }}
            </div>
        @endif

        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-12 h-12 bg-red-50 dark:bg-red-900/20 rounded-2xl flex items-center justify-center">
                    <i class="fa-brands fa-lastfm text-red-600 text-xl"></i>
                </div>
                @if($connected)
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        Connected{{ $username ? " as {$username}" : '' }}
                    </span>
                @endif
            </div>

            @if($connected)
                <p class="text-sm text-gray-500 dark:text-gray-500 mb-6">
                    Plays are scrobbled to Last.fm as they finish, and artist bios/tags are pulled in the background.
                </p>
                <form method="POST" action="{{ route('lastfm.disconnect') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">
                        Disconnect
                    </button>
                </form>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-500 mb-6">
                    Connect your Last.fm account to scrobble plays and enrich artist metadata.
                </p>
                <a href="{{ route('lastfm.authorize') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-xl transition-colors">
                    <i class="fa-brands fa-lastfm"></i> Connect Last.fm
                </a>
            @endif
        </div>
    </div>
</x-app-layout>
