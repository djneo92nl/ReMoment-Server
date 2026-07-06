<aside class="hidden md:flex flex-col w-72 bg-white dark:bg-stone-900 border-r border-gray-200 dark:border-stone-800 shadow-sm flex-shrink-0">
    <div class="p-8 border-b border-gray-200 dark:border-stone-800">
        <div class="flex items-center gap-3">
            <div class="text-2xl font-medium tracking-tight text-gray-900 dark:text-gray-100">Re</div>
            <div class="text-base text-gray-400">×</div>
            <div class="text-2xl font-light text-red-600">Moment</div>
        </div>
        <p class="mt-1 text-xs text-gray-400 tracking-wide">Universal Device Controller</p>
    </div>

    <nav class="flex-1 px-4 py-6">
        <ul class="space-y-1">
            <li>
                <a href="{{ route('devices.index') }}"
                   class="flex items-center gap-3 px-5 py-3.5 rounded-xl transition-colors {{ request()->routeIs('devices.*') ? 'bg-gray-100 dark:bg-stone-800 font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-stone-800/50' }}">
                    <i class="fa-solid fa-tv w-5 {{ request()->routeIs('devices.*') ? 'text-gray-700 dark:text-gray-300' : '' }}"></i>
                    Devices
                </a>
            </li>
            <li>
                <a href="{{ route('history.index') }}"
                   class="flex items-center gap-3 px-5 py-3.5 rounded-xl transition-colors {{ request()->routeIs('history.*') ? 'bg-gray-100 dark:bg-stone-800 font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-stone-800/50' }}">
                    <i class="fa-solid fa-clock-rotate-left w-5 {{ request()->routeIs('history.*') ? 'text-gray-700 dark:text-gray-300' : '' }}"></i>
                    History
                </a>
            </li>
            <li>
                <a href="{{ route('artists.index') }}"
                   class="flex items-center gap-3 px-5 py-3.5 rounded-xl transition-colors {{ request()->routeIs('artists.*') || request()->routeIs('albums.*') || request()->routeIs('radio.*') || request()->routeIs('playlists.*') ? 'bg-gray-100 dark:bg-stone-800 font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-stone-800/50' }}">
                    <i class="fa-solid fa-microphone-lines w-5 {{ request()->routeIs('artists.*') || request()->routeIs('albums.*') || request()->routeIs('radio.*') || request()->routeIs('playlists.*') ? 'text-gray-700 dark:text-gray-300' : '' }}"></i>
                    Library
                </a>
                @if(request()->routeIs('artists.*') || request()->routeIs('albums.*') || request()->routeIs('radio.*') || request()->routeIs('playlists.*'))
                    <ul class="mt-1 ml-4 space-y-1">
                        <li>
                            <a href="{{ route('artists.index') }}"
                               class="flex items-center gap-3 px-5 py-2.5 rounded-xl text-sm transition-colors {{ request()->routeIs('artists.*') || request()->routeIs('albums.*') ? 'text-gray-900 dark:text-gray-100 font-medium' : 'text-gray-500 dark:text-gray-500 hover:text-gray-800 dark:hover:text-gray-300' }}">
                                <i class="fa-solid fa-microphone-lines w-4 text-xs {{ request()->routeIs('artists.*') || request()->routeIs('albums.*') ? 'text-indigo-500' : 'text-gray-300 dark:text-stone-700' }}"></i>
                                Artists
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('playlists.index') }}"
                               class="flex items-center gap-3 px-5 py-2.5 rounded-xl text-sm transition-colors {{ request()->routeIs('playlists.*') ? 'text-gray-900 dark:text-gray-100 font-medium' : 'text-gray-500 dark:text-gray-500 hover:text-gray-800 dark:hover:text-gray-300' }}">
                                <i class="fa-solid fa-list-ul w-4 text-xs {{ request()->routeIs('playlists.*') ? 'text-purple-500' : 'text-gray-300 dark:text-stone-700' }}"></i>
                                Playlists
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('radio.index') }}"
                               class="flex items-center gap-3 px-5 py-2.5 rounded-xl text-sm transition-colors {{ request()->routeIs('radio.*') ? 'text-gray-900 dark:text-gray-100 font-medium' : 'text-gray-500 dark:text-gray-500 hover:text-gray-800 dark:hover:text-gray-300' }}">
                                <i class="fa-solid fa-radio w-4 text-xs {{ request()->routeIs('radio.*') ? 'text-red-500' : 'text-gray-300 dark:text-stone-700' }}"></i>
                                Radio
                            </a>
                        </li>
                    </ul>
                @endif
            </li>
            <li>
                <a href="{{ route('multiroom.index') }}"
                   class="flex items-center gap-3 px-5 py-3.5 rounded-xl transition-colors {{ request()->routeIs('multiroom.*') ? 'bg-gray-100 dark:bg-stone-800 font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-stone-800/50' }}">
                    <i class="fa-solid fa-layer-group w-5 {{ request()->routeIs('multiroom.*') ? 'text-gray-700 dark:text-gray-300' : '' }}"></i>
                    Multiroom
                </a>
            </li>
            <li>
                <a href="{{ route('stats.index') }}"
                   class="flex items-center gap-3 px-5 py-3.5 rounded-xl transition-colors {{ request()->routeIs('stats.*') ? 'bg-gray-100 dark:bg-stone-800 font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-stone-800/50' }}">
                    <i class="fa-solid fa-chart-simple w-5 {{ request()->routeIs('stats.*') ? 'text-gray-700 dark:text-gray-300' : '' }}"></i>
                    Insights
                </a>
            </li>
            <li>
                <a href="{{ route('settings.index') }}"
                   class="flex items-center gap-3 px-5 py-3.5 rounded-xl transition-colors {{ request()->routeIs('settings.*') ? 'bg-gray-100 dark:bg-stone-800 font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-stone-800/50' }}">
                    <i class="fa-solid fa-sliders w-5 {{ request()->routeIs('settings.*') ? 'text-gray-700 dark:text-gray-300' : '' }}"></i>
                    Settings
                </a>
                @if(request()->routeIs('settings.*'))
                    <ul class="mt-1 ml-4 space-y-1">
                        <li>
                            <a href="{{ route('settings.listeners') }}"
                               class="flex items-center gap-3 px-5 py-2.5 rounded-xl text-sm transition-colors {{ request()->routeIs('settings.listeners*') ? 'text-gray-900 dark:text-gray-100 font-medium' : 'text-gray-500 dark:text-gray-500 hover:text-gray-800 dark:hover:text-gray-300' }}">
                                <i class="fa-solid fa-circle-dot w-4 text-xs {{ request()->routeIs('settings.listeners*') ? 'text-emerald-500' : 'text-gray-300 dark:text-stone-700' }}"></i>
                                Listeners
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('settings.users') }}"
                               class="flex items-center gap-3 px-5 py-2.5 rounded-xl text-sm transition-colors {{ request()->routeIs('settings.users*') ? 'text-gray-900 dark:text-gray-100 font-medium' : 'text-gray-500 dark:text-gray-500 hover:text-gray-800 dark:hover:text-gray-300' }}">
                                <i class="fa-solid fa-users w-4 text-xs {{ request()->routeIs('settings.users*') ? 'text-blue-500' : 'text-gray-300 dark:text-stone-700' }}"></i>
                                Users
                            </a>
                        </li>
                    </ul>
                @endif
            </li>
        </ul>
    </nav>

    <div class="p-6 border-t border-gray-200 dark:border-stone-800 text-xs text-gray-400 space-y-1">
        <div>{{ config('app.name', 'ReMoment') }}</div>
        <div class="text-gray-300 dark:text-stone-600">{{ now()->format('M j, Y') }}</div>
    </div>
</aside>
