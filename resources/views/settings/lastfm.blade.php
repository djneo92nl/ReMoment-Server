<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Last.fm" subtitle="Scrobble plays and enrich artist metadata" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-2xl space-y-6">

        <x-alert type="success">{{ session('success') }}</x-alert>

        <x-alert type="error">{{ session('error') }}</x-alert>

        <x-card class="p-8">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-12 h-12 bg-gray-100 dark:bg-stone-800 rounded-2xl flex items-center justify-center">
                    <i class="fa-brands fa-lastfm text-gray-500 text-xl"></i>
                </div>
                @if($connected)
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        Connected{{ $username ? " as {$username}" : '' }}
                    </span>
                @endif
            </div>

            @if($connected)
                <p class="text-sm text-gray-500 mb-6">
                    Plays are scrobbled to Last.fm as they finish, and artist bios/tags are pulled in the background.
                </p>
                <form method="POST" action="{{ route('settings.lastfm.sources') }}" class="mb-6">
                    @csrf
                    <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-1">Scrobble streaming sources</h2>
                    <p class="text-sm text-gray-500 mb-3">
                        Turn a source off when the service scrobbles to Last.fm itself, so plays aren't counted twice.
                    </p>
                    <div class="space-y-2 mb-4">
                        @foreach($streamingSources as $key => $label)
                            <label class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" name="sources[]" value="{{ $key }}"
                                       @checked(!in_array($key, $disabledSources, true))
                                       class="rounded border-gray-300 dark:border-stone-700">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <x-primary-button size="md">Save</x-primary-button>
                </form>
                <form method="POST" action="{{ route('lastfm.disconnect') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">
                        Disconnect
                    </button>
                </form>
            @else
                <p class="text-sm text-gray-500 mb-6">
                    Connect your Last.fm account to scrobble plays and enrich artist metadata.
                </p>
                <x-primary-button size="md" href="{{ route('lastfm.authorize') }}"><i class="fa-brands fa-lastfm"></i> Connect Last.fm</x-primary-button>
            @endif
        </x-card>
    </div>
</x-app-layout>
