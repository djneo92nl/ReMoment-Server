<x-app-layout>
    <x-slot name="header">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Albums</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">{{ number_format($albums->total()) }} albums in your library</p>
            </div>

            <form method="GET" action="{{ route('albums.index') }}">
                <select name="sort" onchange="this.form.submit()"
                        class="rounded-xl border-gray-200 dark:border-stone-700 dark:bg-stone-900 text-sm text-gray-600 dark:text-gray-400 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="plays" @selected($sort === 'plays')>Most played</option>
                    <option value="recent" @selected($sort === 'recent')>Recently added</option>
                    <option value="name" @selected($sort === 'name')>Name</option>
                    <option value="artist" @selected($sort === 'artist')>Artist</option>
                </select>
            </form>
        </div>
    </x-slot>

    @if($albums->isEmpty())
        <div class="bg-white dark:bg-stone-900 rounded-3xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-16 text-center">
            <i class="fa-solid fa-compact-disc text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
            <p class="text-gray-400 dark:text-gray-600 text-sm">No albums yet — start listening to build your library</p>
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-5">
            @foreach($albums as $album)
                <div class="group">
                    <a href="{{ route('albums.show', $album) }}">
                        <x-artwork-thumb
                            :src="$album->images[0]['url'] ?? null"
                            :colors="$album->colors"
                            :seed="$album->name.$album->id"
                            icon="fa-solid fa-compact-disc"
                            size="w-full aspect-square"
                            rounded="rounded-2xl"
                            class="shadow-sm ring-1 ring-gray-100 dark:ring-stone-800 group-hover:shadow-md transition-shadow"
                        />
                        <p class="mt-3 text-sm font-medium text-gray-900 dark:text-gray-100 truncate group-hover:underline">{{ $album->name }}</p>
                    </a>
                    <a href="{{ route('artists.show', $album->artist) }}" class="text-xs text-gray-500 dark:text-gray-500 truncate hover:underline block">
                        {{ $album->artist->name }}
                    </a>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">
                        @if($album->plays_count > 0)
                            {{ number_format($album->plays_count) }} {{ Str::plural('play', $album->plays_count) }}
                        @endif
                        @if($album->released_at)
                            @if($album->plays_count > 0) &middot; @endif
                            {{ $album->released_at->format('Y') }}
                        @endif
                    </p>
                </div>
            @endforeach
        </div>

        @if($albums->hasPages())
            <div class="mt-8 flex justify-center">
                {{ $albums->links() }}
            </div>
        @endif
    @endif
</x-app-layout>
