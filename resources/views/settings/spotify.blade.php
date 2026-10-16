<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Spotify" subtitle="Connect your Spotify account to track playback" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-2xl space-y-6">

        <x-alert type="success">{{ session('success') }}</x-alert>

        <x-card class="p-8">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-12 h-12 bg-gray-100 dark:bg-stone-800 rounded-2xl flex items-center justify-center">
                    <i class="fa-brands fa-spotify text-gray-500 text-xl"></i>
                </div>
                @if($connected)
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>Connected
                    </span>
                @endif
            </div>

            @if($connected)
                <p class="text-sm text-gray-500 mb-6">
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
                <p class="text-sm text-gray-500 mb-6">
                    Connect your Spotify account to track playback.
                </p>
                <x-primary-button size="md" href="{{ route('spotify.authorize') }}"><i class="fa-brands fa-spotify"></i> Connect Spotify</x-primary-button>
            @endif
        </x-card>

        @if($connected)
            <x-settings-group>
                <x-settings-row href="{{ route('settings.spotify-connect') }}"
                                 icon="fa-solid fa-tower-broadcast"
                                 label="Speaker Mappings" />
                <x-settings-row href="{{ route('settings.spotify-library') }}"
                                 icon="fa-solid fa-book"
                                 label="Library Sync" />
            </x-settings-group>
        @endif
    </div>
</x-app-layout>
