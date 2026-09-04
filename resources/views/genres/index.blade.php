<x-app-layout>
    <x-slot name="header">
        <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Genres</h1>
        <p class="mt-1.5 text-gray-500 dark:text-gray-500">{{ number_format($genres->count()) }} genres in your library</p>
    </x-slot>

    @if($genres->isEmpty())
        <div class="bg-white dark:bg-stone-900 rounded-3xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-16 text-center">
            <i class="fa-solid fa-tags text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
            <p class="text-gray-400 dark:text-gray-600 text-sm">No genre data yet — genres are enriched once artists have played tracks</p>
        </div>
    @else
        <div class="flex flex-wrap gap-3">
            @foreach($genres as $genre)
                <a href="{{ route('genres.show', $genre['name']) }}"
                   class="flex items-center gap-2 px-5 py-3 rounded-2xl bg-white dark:bg-stone-900 border border-gray-200/70 dark:border-stone-800/80 shadow-sm hover:border-gray-300 dark:hover:border-stone-700 hover:shadow-md transition-all">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $genre['name'] }}</span>
                    <span class="text-xs text-gray-400 dark:text-gray-600">{{ number_format($genre['artist_count']) }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-app-layout>
