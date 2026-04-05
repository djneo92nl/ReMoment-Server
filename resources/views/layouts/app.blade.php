<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-gray-50">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=SF+Pro+Display:wght@300;400;500;600;700&display=swap');
        body { font-family: 'SF Pro Display', system-ui, -apple-system, sans-serif; }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 text-gray-900 dark:text-gray-300 dark:bg-stone-950 dark:from-stone-950 dark:to-stone-80 antialiased">

<div class="flex min-h-screen">
    <!-- Main Content -->
    <main class="flex-1 pt-5 pb-12 px-5 sm:px-8 lg:px-12">
        <!-- Mobile top bar -->
        <div class="md:hidden flex items-center justify-between mb-8 bg-white/80 backdrop-blur-lg p-4 -mx-5 -mt-5 border-b border-gray-200 sticky top-0 z-10">
            @isset($title)
                <div class="text-xl font-medium">{{ $title }}</div>
            @else
                <div class="text-xl font-medium">{{ config('app.name', 'Laravel') }}</div>
            @endisset
            <button class="text-gray-600">
                <i class="fa-solid fa-bars text-xl"></i>
            </button>
        </div>

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
