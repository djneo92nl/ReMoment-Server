<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Sync" subtitle="Keep a USB stick or an MP3 player filled with part of your library" />
    </x-slot>

    <div class="max-w-5xl space-y-6">
        <x-card class="p-5 sm:p-6" data-test="sync-how">
            <h2 class="mb-2 text-base font-medium tracking-tight text-gray-900 dark:text-gray-100">How it works</h2>
            <ol class="list-decimal space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-400">
                <li>Plug the stick into a ReMoment Node (a Pi, or the server itself with the node running) and add it below.</li>
                <li>Choose what goes on it: playlists, favourites, the newest or most played tracks, within a size budget.</li>
                <li>Press <strong class="font-medium text-gray-800 dark:text-gray-200">Sync now</strong>. Only what changed is copied or removed; files that are not ours are never touched.</li>
                <li>When the sync is done, remove the stick. The page cannot eject it for you.</li>
            </ol>
        </x-card>

        @auth
            <livewire:sync-targets />
        @else
            <x-alert type="info">Sync needs the admin login. <a href="{{ route('login') }}" class="underline">Sign in</a> to manage sync targets.</x-alert>
        @endauth
    </div>
</x-app-layout>
