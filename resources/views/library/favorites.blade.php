<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Favorites" :subtitle="number_format($albums->count()).' '.Str::plural('album', $albums->count()).' · '.number_format($artists->count()).' '.Str::plural('artist', $artists->count())" :back="route('library.index')">
            <x-slot name="actions"><x-library-scope :scope="$scope" /></x-slot>
        </x-page-header>
    </x-slot>

    @if($albums->isEmpty() && $artists->isEmpty())
        <x-empty-state icon="heart" title="No favorites yet" text="Tap the heart on an album or an artist to keep it here." />
    @else
        <div class="space-y-9">
            @if($albums->isNotEmpty())
                <section>
                    <h2 class="mb-4 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">Albums</h2>
                    <x-album-grid>
                        @foreach($albums as $album)
                            <x-album-card :album="$album" quick />
                        @endforeach
                    </x-album-grid>
                </section>
            @endif

            @if($artists->isNotEmpty())
                <section>
                    <h2 class="mb-4 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">Artists</h2>
                    <x-album-grid>
                        @foreach($artists as $artist)
                            <x-artist-card :artist="$artist" />
                        @endforeach
                    </x-album-grid>
                </section>
            @endif
        </div>
    @endif
</x-app-layout>
