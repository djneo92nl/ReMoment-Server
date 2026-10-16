<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Favorites" :subtitle="number_format($albums->count()).' '.Str::plural('album', $albums->count()).' · '.number_format($artists->count()).' '.Str::plural('artist', $artists->count()).' · '.number_format($tracks->count()).' '.Str::plural('track', $tracks->count())" :back="route('library.index')">
            <x-slot name="actions"><x-library-scope :scope="$scope" /></x-slot>
        </x-page-header>
    </x-slot>

    @if($albums->isEmpty() && $artists->isEmpty() && $tracks->isEmpty())
        <x-empty-state icon="heart" title="No favorites yet" text="Tap the heart on an album, an artist or a track to keep it here." />
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

            @if($tracks->isNotEmpty())
                @php $pinned = app(\App\Support\SelectedDevice::class)->device(); @endphp
                <section>
                    <h2 class="mb-4 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">Tracks</h2>
                    <x-card flush class="divide-y divide-gray-100 dark:divide-stone-800">
                        @foreach($tracks as $track)
                            <div class="flex items-center gap-3 px-4 py-2.5">
                                @if($pinned)
                                    <form method="POST" action="{{ route('tracks.play', [$track, $pinned]) }}" class="flex-none">
                                        @csrf
                                        <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-stone-800 dark:hover:text-gray-100" title="Play on {{ $pinned->device_name }}"><i class="fa-solid fa-play text-xs"></i></button>
                                    </form>
                                @else
                                    <button type="button" @click="$dispatch('open-player-panel')" class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-stone-800 dark:hover:text-gray-100" title="Choose a player to play on"><i class="fa-solid fa-play text-xs"></i></button>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-gray-900 dark:text-gray-100">{{ $track->name }}</p>
                                    <p class="truncate text-xs text-gray-500">
                                        @if($track->artist)<a href="{{ route('artists.show', $track->artist) }}" class="hover:underline">{{ $track->artist->name }}</a>@endif
                                        @if($track->album) · <a href="{{ route('albums.show', $track->album) }}#track-{{ $track->id }}" class="hover:underline">{{ $track->album->name }}</a>@endif
                                    </p>
                                </div>
                                @if($track->duration)
                                    <span class="flex-none text-xs tabular-nums text-gray-400 dark:text-stone-500">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $track->duration) }}</span>
                                @endif
                                <form method="POST" action="{{ route('tracks.favorite', $track) }}" class="flex-none">
                                    @csrf
                                    <button type="submit" class="text-rose-500 hover:text-rose-600" title="Remove from favorites"><i class="fa-solid fa-heart"></i></button>
                                </form>
                            </div>
                        @endforeach
                    </x-card>
                </section>
            @endif
        </div>
    @endif
</x-app-layout>
