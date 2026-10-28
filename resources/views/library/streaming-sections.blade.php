{{-- The Streaming scope of /library: shelves of your Spotify, read live (nothing is stored), in the same style as the library's own shelves.
     Spotify playlists are not here: they have their own page (/playlists, Streaming). --}}
@if(app(\App\Services\SpotifyTokenService::class)->isConnected())
    <livewire:streaming-section section="liked-tracks" :key="'streaming-liked-tracks'" />
    <livewire:streaming-section section="saved-albums" :key="'streaming-saved-albums'" />
    <livewire:streaming-section section="recent" :key="'streaming-recent'" />
@else
    <p class="text-sm text-gray-400 dark:text-gray-600" data-test="streaming-sections"><i class="fa-brands fa-spotify mr-1.5 text-emerald-500"></i>Spotify is not connected@auth. <a href="{{ route('settings.spotify') }}" class="underline">Connect it</a> to see your liked songs, saved albums and recently played here@endauth.</p>
@endif
