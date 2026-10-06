<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back-button href="{{ route('settings.index') }}" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Library</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Which source leads the library</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl space-y-6">
        @if(session('success'))
            <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-2xl px-6 py-4 text-sm text-emerald-800 dark:text-emerald-300">
                <i class="fa-solid fa-circle-check mr-2"></i>{{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('settings.library.save') }}"
              class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8 space-y-5">
            @csrf
            <p class="text-sm text-gray-500 dark:text-gray-500">
                The leading source is what the library pages and the API show by default; the other source extends it.
                Currently leading: <strong class="text-gray-800 dark:text-gray-200">{{ ['dlna' => 'DLNA', 'spotify' => 'Spotify', 'all' => 'everything'][$leading] }}</strong>.
            </p>

            @foreach(['auto' => ['Automatic', 'DLNA when it has imported tracks, otherwise Spotify.'], 'dlna' => ['DLNA', 'Always lead with the DLNA library.'], 'spotify' => ['Spotify', 'Always lead with Spotify.'], 'all' => ['Everything', 'No leading source: show every source together.']] as $value => [$label, $hint])
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="radio" name="leading_source" value="{{ $value }}" @checked($mode === $value) class="mt-1 text-indigo-600 focus:ring-indigo-500">
                    <span>
                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $label }}</span>
                        <span class="block text-xs text-gray-500">{{ $hint }}</span>
                    </span>
                </label>
            @endforeach

            <label class="flex items-start gap-3 cursor-pointer pt-5 border-t border-gray-100 dark:border-stone-800">
                <input type="checkbox" name="add_played_tracks" value="1" @checked($addPlayed) class="mt-1 rounded text-indigo-600 focus:ring-indigo-500">
                <span>
                    <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">Add played tracks to the library</span>
                    <span class="block text-xs text-gray-500">Off (default): plays are still logged in the history, with their cover, but tracks that aren't in the library stay out of it.</span>
                </span>
            </label>

            <button type="submit" class="px-5 py-2.5 rounded-xl bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-sm font-medium">Save</button>
        </form>
    </div>
</x-app-layout>
