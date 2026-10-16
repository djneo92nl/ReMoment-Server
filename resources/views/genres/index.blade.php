<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Genres" :subtitle="number_format($genres->count()).' genres in your library'" />
    </x-slot>

    @if($genres->isEmpty())
        <x-empty-state icon="tags" text="No genre data yet — genres are enriched once tracks have played or been scanned" />
    @else
        <div class="flex flex-wrap gap-3">
            @foreach($genres as $genre)
                <x-card compact :href="route('genres.show', $genre->slug)" class="!flex items-center gap-2 px-5 py-3">
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $genre->name }}</span>
                    <span class="text-xs text-gray-400 dark:text-gray-600">{{ number_format($genre->artists_count ?: $genre->albums_count) }}</span>
                </x-card>
            @endforeach
        </div>
    @endif
</x-app-layout>
