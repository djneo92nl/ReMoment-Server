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
    <style>
@view-transition { navigation: auto; }
/* only the named parts (cover, bar) morph between pages; the rest of a page switches as before */
::view-transition-old(root), ::view-transition-new(root) { animation: none; }
::view-transition-group(np-cover), ::view-transition-group(np-bar) { animation-duration: 0.5s; animation-timing-function: cubic-bezier(0.3, 0.7, 0.2, 1); }
    </style>
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
        @foreach(['success', 'error'] as $flash)
            @if(session($flash))
                <x-alert :type="$flash" class="mb-6">{{ session($flash) }}</x-alert>
            @endif
        @endforeach

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
