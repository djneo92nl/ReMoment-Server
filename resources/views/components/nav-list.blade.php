{{-- The main navigation: the single list behind both the desktop sidebar and the mobile drawer. --}}
@php
    $on = fn (string ...$patterns) => request()->routeIs(...$patterns);
    $libraryActive = $on('library.*', 'artists.*', 'albums.*', 'genres.*', 'radio.*', 'playlists.*');
    $settingsActive = $on('settings.*');
@endphp
<nav class="flex-1 px-4 py-6" aria-label="Main">
    <ul class="space-y-1">
        <li><x-nav-item :href="route('devices.index')" icon="tv" :active="$on('devices.*')">Devices</x-nav-item></li>
        <li><x-nav-item :href="route('history.index')" icon="clock-rotate-left" :active="$on('history.*')">History</x-nav-item></li>
        <li>
            <x-nav-item :href="route('library.index')" icon="microphone-lines" :active="$libraryActive">Library</x-nav-item>
            @if($libraryActive)
                <ul class="mt-1 ml-4 space-y-1">
                    <li><x-nav-item sub :href="route('artists.index')" icon="microphone-lines" :active="$on('artists.*')">Artists</x-nav-item></li>
                    <li><x-nav-item sub :href="route('albums.index')" icon="compact-disc" :active="$on('albums.*')">Albums</x-nav-item></li>
                    <li><x-nav-item sub :href="route('playlists.index')" icon="list-ul" :active="$on('playlists.*')">Playlists</x-nav-item></li>
                    <li><x-nav-item sub :href="route('radio.index')" icon="radio" :active="$on('radio.*')">Radio</x-nav-item></li>
                    <li><x-nav-item sub :href="route('genres.index')" icon="tags" :active="$on('genres.*')">Genres</x-nav-item></li>
                </ul>
            @endif
        </li>
        <li><x-nav-item :href="route('multiroom.index')" icon="layer-group" :active="$on('multiroom.*')">Multiroom</x-nav-item></li>
        {{-- Its own tab: this browser plays the sound while the tab stays open --}}
        <li><x-nav-item :href="route('player')" icon="headphones" target="_blank" rel="noopener">Web player</x-nav-item></li>
        <li><x-nav-item :href="route('stats.index')" icon="chart-simple" :active="$on('stats.*')">Insights</x-nav-item></li>
        <li>
            <x-nav-item :href="route('settings.index')" icon="sliders" :active="$settingsActive">Settings</x-nav-item>
            @if($settingsActive)
                <ul class="mt-1 ml-4 space-y-1">
                    <li><x-nav-item sub :href="route('settings.users')" icon="users" :active="$on('settings.users*')">Users</x-nav-item></li>
                </ul>
            @endif
        </li>
    </ul>

    {{-- With no player pinned the bar is gone: this opens the picker --}}
    @unless(app(\App\Support\SelectedDevice::class)->device())
        <button type="button" @click="mobileMenuOpen = false; $dispatch('open-player-panel')"
                class="mt-1 flex items-center gap-3 px-5 py-3.5 rounded-xl w-full text-left text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-stone-800/50 transition-colors">
            <i class="fa-solid fa-circle-play w-5"></i>Select player
        </button>
    @endunless
</nav>
