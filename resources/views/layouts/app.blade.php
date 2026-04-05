<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-gray-50">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'ReMoment') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=SF+Pro+Display:wght@300;400;500;600;700&display=swap');
        body { font-family: 'SF Pro Display', system-ui, -apple-system, sans-serif; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; width: 14px; height: 14px; border-radius: 50%; background: #374151; cursor: pointer; }
        input[type=range]::-webkit-slider-runnable-track { height: 6px; border-radius: 9999px; background: #e5e7eb; }
        [x-cloak] { display: none !important; }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 text-gray-900 dark:text-gray-300 dark:bg-stone-950 dark:from-stone-950 dark:to-stone-900 antialiased"
      x-data="{ mobileMenuOpen: false }">

<div class="flex min-h-screen">

    <!-- Sidebar Navigation -->
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

    <!-- Main Content -->
    <main class="flex-1 pt-5 pb-12 px-5 sm:px-8 lg:px-12 min-w-0">

        <!-- Mobile top bar -->
        <div class="md:hidden flex items-center justify-between mb-8 bg-white/80 dark:bg-stone-900/80 backdrop-blur-lg p-4 -mx-5 -mt-5 border-b border-gray-200 dark:border-stone-800 sticky top-0 z-10">
            <div class="flex items-center gap-3">
                <span class="text-xl font-medium tracking-tight">Re<span class="font-light text-red-600">Moment</span></span>
            </div>
            <button @click="mobileMenuOpen = !mobileMenuOpen" class="text-gray-600 dark:text-gray-400">
                <i class="fa-solid fa-bars text-xl"></i>
            </button>
        </div>

        <!-- Mobile menu overlay -->
        <div x-show="mobileMenuOpen"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             @click="mobileMenuOpen = false"
             class="md:hidden fixed inset-0 bg-black/40 z-20"></div>

        <div x-show="mobileMenuOpen"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-x-full"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-x-0"
             x-transition:leave-end="opacity-0 -translate-x-full"
             class="md:hidden fixed top-0 left-0 h-full w-72 bg-white dark:bg-stone-900 z-30 shadow-xl flex flex-col">
            <div class="p-8 border-b border-gray-200 dark:border-stone-800">
                <div class="flex items-center gap-3">
                    <div class="text-2xl font-medium tracking-tight text-gray-900 dark:text-gray-100">Re</div>
                    <div class="text-base text-gray-400">×</div>
                    <div class="text-2xl font-light text-red-600">Moment</div>
                </div>
            </div>
            <nav class="flex-1 px-4 py-6">
                <ul class="space-y-1">
                    <li>
                        <a href="{{ route('devices.index') }}" @click="mobileMenuOpen = false"
                           class="flex items-center gap-3 px-5 py-3.5 rounded-xl transition-colors {{ request()->routeIs('devices.*') ? 'bg-gray-100 dark:bg-stone-800 font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400' }}">
                            <i class="fa-solid fa-tv w-5"></i>Devices
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('history.index') }}" @click="mobileMenuOpen = false"
                           class="flex items-center gap-3 px-5 py-3.5 rounded-xl transition-colors {{ request()->routeIs('history.*') ? 'bg-gray-100 dark:bg-stone-800 font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400' }}">
                            <i class="fa-solid fa-clock-rotate-left w-5"></i>History
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('settings.index') }}" @click="mobileMenuOpen = false"
                           class="flex items-center gap-3 px-5 py-3.5 rounded-xl transition-colors {{ request()->routeIs('settings.*') ? 'bg-gray-100 dark:bg-stone-800 font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400' }}">
                            <i class="fa-solid fa-sliders w-5"></i>Settings
                        </a>
                    </li>
                </ul>
            </nav>
        </div>

        <!-- Flash messages -->
        @if(session('success'))
            <div class="mb-6 flex items-center gap-3 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 rounded-2xl px-6 py-4 text-sm">
                <i class="fa-solid fa-circle-check"></i>
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-6 flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300 rounded-2xl px-6 py-4 text-sm">
                <i class="fa-solid fa-circle-exclamation"></i>
                {{ session('error') }}
            </div>
        @endif

        <!-- Page Heading -->
        @isset($header)
            <div class="mb-10">
                {{ $header }}
            </div>
        @endisset

        <!-- Page Content -->
        {{ $slot }}
    </main>
</div>

</body>
</html>
