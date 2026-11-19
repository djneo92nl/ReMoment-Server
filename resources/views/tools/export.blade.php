<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Export" subtitle="Your music as a zip for an MP3 player, a car radio or a phone" />
    </x-slot>

    <div class="max-w-5xl space-y-6">
        <x-card class="p-5 sm:p-6" data-test="export-how">
            <h2 class="mb-2 text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">How it works</h2>
            <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-400">
                <li>Pick artists, albums, tracks and playlists below (or press <strong class="font-medium text-gray-800 dark:text-gray-200">Export</strong> on an album, artist or playlist page).</li>
                <li>Pick a profile (MP3, AAC or Opus for small players, or the originals), optionally with level-matched loudness.</li>
                <li>The server prepares the zip in the background; the list below shows the progress and a download when it is ready.</li>
            </ol>
            <p class="mt-3 text-xs text-gray-500">Exports are kept for {{ config('export.keep_hours') }} hours and then removed.</p>
        </x-card>

        @auth
            <livewire:export-from-library />
            <livewire:export-list />
        @else
            <x-alert type="info">Exporting needs the admin login. <a href="{{ route('login') }}" class="underline">Sign in</a> to start exports and see the list.</x-alert>
        @endauth
    </div>
</x-app-layout>
