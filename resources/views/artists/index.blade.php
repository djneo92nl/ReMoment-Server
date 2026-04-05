<x-app-layout>
    <x-slot name="header">
        <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Artists</h1>
        <p class="mt-1.5 text-gray-500 dark:text-gray-500">{{ number_format($artists->total()) }} artists in your library</p>
    </x-slot>

    @if($artists->isEmpty())
        <div class="bg-white dark:bg-stone-900 rounded-3xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-16 text-center">
            <i class="fa-solid fa-microphone-lines text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
            <p class="text-gray-400 dark:text-gray-600 text-sm">No artists yet — start listening to build your library</p>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($artists as $artist)
                <a href="{{ route('artists.show', $artist) }}"
                   class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-5 hover:border-gray-300 dark:hover:border-stone-700 hover:shadow-md transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-stone-800 flex items-center justify-center flex-shrink-0 group-hover:bg-gray-200 dark:group-hover:bg-stone-700 transition-colors">
                            <i class="fa-solid fa-microphone-lines text-gray-400 dark:text-gray-500"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $artist->name }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">
                                {{ number_format($artist->plays_count) }} {{ Str::plural('play', $artist->plays_count) }}
                            </p>
                        </div>
                        <i class="fa-solid fa-chevron-right text-gray-200 dark:text-stone-700 text-xs group-hover:text-gray-400 dark:group-hover:text-stone-500 transition-colors"></i>
                    </div>
                </a>
            @endforeach
        </div>

        @if($artists->hasPages())
            <div class="mt-8 flex justify-center">
                {{ $artists->links() }}
            </div>
        @endif
    @endif
</x-app-layout>
