<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-gray-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'ReMoment') }}</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=SF+Pro+Display:wght@300;400;500;600;700&display=swap');
        body { font-family: 'SF Pro Display', system-ui, -apple-system, sans-serif; }
    </style>
    @livewireScriptConfig
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 text-gray-900 dark:text-gray-300 dark:bg-stone-950 dark:from-stone-950 dark:to-stone-900 antialiased">
    <div class="min-h-screen flex flex-col items-center justify-center px-4">

        <!-- Branding -->
        <div class="mb-8 text-center">
            <div class="flex items-center justify-center gap-3 mb-1">
                <span class="text-3xl font-medium tracking-tight text-gray-900 dark:text-gray-100">Re</span>
                <span class="text-xl text-gray-400">×</span>
                <span class="text-3xl font-light text-red-600">Moment</span>
            </div>
            <p class="text-xs text-gray-400 tracking-wide">Universal Device Controller</p>
        </div>

        <!-- Card -->
        <div class="w-full max-w-sm bg-white dark:bg-stone-900 border border-gray-200 dark:border-stone-800 rounded-2xl shadow-sm px-8 py-8">
            {{ $slot }}
        </div>

    </div>
</body>
</html>
