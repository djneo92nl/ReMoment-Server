<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start gap-4">
            <x-back-button href="{{ route('genres.index') }}" class="mt-1" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">{{ $genre->name }}</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">{{ number_format($artists->count()) }} {{ Str::plural('artist', $artists->count()) }} · {{ number_format($albums->count()) }} {{ Str::plural('album', $albums->count()) }}</p>
            </div>
        </div>
    </x-slot>

    @if($artists->isNotEmpty())
    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-4">Artists</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        @foreach($artists as $artist)
            @php($coverAlbum = $artist->coverAlbum())
            <a href="{{ route('artists.show', $artist) }}"
               class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-5 hover:border-gray-300 dark:hover:border-stone-700 hover:shadow-md transition-all group">
                <div class="flex items-center gap-4">
                    <x-artwork-thumb
                        :src="$coverAlbum?->images[0]['url'] ?? null"
                        :colors="$coverAlbum?->colors"
                        :seed="$artist->name"
                        icon="fa-solid fa-microphone-lines"
                        size="w-12 h-12"
                        rounded="rounded-xl"
                    />
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
    @endif

    @if($albums->isNotEmpty())
        <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mt-10 mb-4">Albums</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($albums as $album)
                <a href="{{ route('albums.show', $album) }}"
                   class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 shadow-sm p-5 hover:border-gray-300 dark:hover:border-stone-700 hover:shadow-md transition-all">
                    <div class="flex items-center gap-4">
                        <x-artwork-thumb
                            :src="$album->images[0]['url'] ?? null"
                            :colors="$album->colors"
                            :seed="$album->name.$album->id"
                            icon="fa-solid fa-compact-disc"
                            size="w-12 h-12"
                            rounded="rounded-xl"
                        />
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $album->name }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 truncate">{{ $album->artist?->name }}</p>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</x-app-layout>
