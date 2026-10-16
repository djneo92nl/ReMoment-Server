<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$genre->name"
                       :subtitle="number_format($artists->count()).' '.Str::plural('artist', $artists->count()).' · '.number_format($albums->count()).' '.Str::plural('album', $albums->count())"
                       :back="route('genres.index')" />
    </x-slot>

    @if($artists->isNotEmpty())
    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-4">Artists</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        @foreach($artists as $artist)
            @php($coverAlbum = $artist->coverAlbum())
            <x-card compact :href="route('artists.show', $artist)" class="p-5 group">
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
            </x-card>
        @endforeach
    </div>
    @endif

    @if($albums->isNotEmpty())
        <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mt-10 mb-4">Albums</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($albums as $album)
                <x-card compact :href="route('albums.show', $album)" class="p-5">
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
                </x-card>
            @endforeach
        </div>
    @endif
</x-app-layout>
