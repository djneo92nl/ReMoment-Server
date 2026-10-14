<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-gray-50">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'ReMoment') }}</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <x-live-scripts />
    @livewireScriptConfig
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 text-gray-900 dark:text-gray-300 dark:bg-stone-950 dark:from-stone-950 dark:to-stone-900 antialiased"
      x-data="{ mobileMenuOpen: false }">

<x-nav-mobile />

<div class="flex min-h-screen">

    <x-nav-sidebar />

    <!-- Main Content -->
    <main class="flex-1 pt-5 pb-12 px-5 sm:px-8 lg:px-12 min-w-0">

        <!-- Global search -->
        <div class="mb-6">
            <livewire:global-search />
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

<!-- Sticky player: the pinned device, and the default target of every play button. Sticky (not fixed) at the end of the page, so content ends above it instead of hiding behind it. Geometry: .player-dock in app.css. -->
<div class="player-dock">
    <livewire:player-bar />
</div>
</body>
</html>
