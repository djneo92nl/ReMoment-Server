<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="bg-gray-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">

    <title>{{ $device->device_name }} · {{ config('app.name', 'ReMoment') }}</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    @livewireScriptConfig
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- No sidebar, search or player bar: a guest gets this page and nothing else. --}}
<body class="min-h-screen bg-gray-50 text-gray-900 dark:text-gray-300 dark:bg-stone-950 antialiased">
    <main class="mx-auto w-full max-w-md px-4 py-6">
        <header class="mb-5">
            <x-wordmark size="md" class="mb-3" />
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Add a track</h1>
            <p class="text-sm text-gray-500">Playing on {{ $device->device_name }}</p>
        </header>

        <livewire:party-queue :token="$token" :visitor="$visitor" />
    </main>
</body>
</html>
