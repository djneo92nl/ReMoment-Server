<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Spotify Library" subtitle="Import saved tracks and playlists from your Spotify account" :back="route('settings.spotify')" />
    </x-slot>

    <div class="max-w-4xl space-y-6">

        @if(session('success'))
            <x-alert type="success">{{ session('success') }}</x-alert>
        @endif

        <x-alert type="info">
            Sync your saved tracks and playlists into the shared library. This also runs automatically once a day. You can
            trigger it manually from the command line with
            <code class="font-mono">php artisan spotify:sync-library</code>.
        </x-alert>

        @if(!$connected)
            <x-empty-state icon="fa-brands fa-spotify" title="Spotify is not connected yet.">
                <a href="{{ route('settings.spotify') }}" class="text-xs text-gray-900 dark:text-gray-100 underline">Connect Spotify from Settings →</a>
            </x-empty-state>
        @else
            @if(!$hasRequiredScopes)
                <x-alert type="warning">
                    Reconnect Spotify to grant library access permissions.
                    <x-slot name="action"><x-primary-button size="sm" href="{{ route('spotify.authorize') }}">Reconnect</x-primary-button></x-slot>
                </x-alert>
            @else
                <x-card flush>
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
                                <x-secondary-button size="sm" type="submit">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                    Sync
                                </x-secondary-button>
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
                                <x-secondary-button size="sm" type="submit">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                    Sync
                                </x-secondary-button>
                            </form>
                        </div>
                    </div>
                </x-card>
            @endif
        @endif
    </div>
</x-app-layout>
