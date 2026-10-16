<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Albums" :subtitle="number_format($albums->total()).' '.Str::plural('album', $albums->total()).($search !== '' ? ' matching “'.$search.'”' : '')">
            <x-slot name="actions"><x-library-scope :scope="$scope" /></x-slot>
        </x-page-header>
    </x-slot>

    <form method="GET" action="{{ route('albums.index') }}" class="mb-6 flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-[12rem] max-w-sm">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
            <x-text-input type="search" name="q" value="{{ $search }}" placeholder="Search albums or artists" class="w-full pl-8" />
        </div>
        <x-select name="sort" onchange="this.form.submit()">
            <option value="recent" @selected($sort === 'recent')>Recently added</option>
            <option value="plays" @selected($sort === 'plays')>Most played</option>
            <option value="year" @selected($sort === 'year')>Release year</option>
            <option value="name" @selected($sort === 'name')>Album A–Z</option>
            <option value="artist" @selected($sort === 'artist')>Artist A–Z</option>
            <option value="rating" @selected($sort === 'rating')>Highest rated</option>
            <option value="listeners" @selected($sort === 'listeners')>Most listened (Last.fm)</option>
        </x-select>
        @if($filters['genres']->isNotEmpty())
            <x-select name="genre" onchange="this.form.submit()">
                <option value="">All genres</option>
                @foreach($filters['genres'] as $g)
                    <option value="{{ $g->slug }}" @selected($filters['genre'] === $g->slug)>{{ $g->name }}</option>
                @endforeach
            </x-select>
        @endif
        @if($filters['moods'])
            <x-select name="mood" onchange="this.form.submit()">
                <option value="">Any mood</option>
                @foreach($filters['moods'] as $m)
                    <option value="{{ $m }}" @selected($filters['mood'] === $m)>{{ ucfirst($m) }}</option>
                @endforeach
            </x-select>
        @endif
        @if($filters['tempos'])
            <x-select name="tempo" onchange="this.form.submit()">
                <option value="">Any tempo</option>
                @foreach(['slow' => 'Slow (under 90 BPM)', 'steady' => 'Steady (90–125)', 'fast' => 'Fast (over 125)'] as $key => $label)
                    @if(in_array($key, $filters['tempos'], true))
                        <option value="{{ $key }}" @selected($filters['tempo'] === $key)>{{ $label }}</option>
                    @endif
                @endforeach
            </x-select>
        @endif
        <label class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 px-2">
            <input type="checkbox" name="fav" value="1" @checked($favorites) onchange="this.form.submit()" class="rounded text-gray-900 dark:text-stone-500 focus:ring-gray-400">
            Favorites
        </label>
        <button type="submit" class="sr-only">Apply</button>
    </form>

    @if($albums->isEmpty())
        <x-empty-state icon="compact-disc" :text="'No albums here'.(($search !== '' || $favorites || $filters['genre'] !== '' || $filters['mood'] !== '' || $filters['tempo'] !== '') ? ' for these filters' : ' yet').'.'" />
    @else
        <x-album-grid>
            @foreach($albums as $album)
                <x-album-card :album="$album" :show-plays="$sort === 'plays'" detail />
            @endforeach
        </x-album-grid>

        @if($albums->hasPages())
            <div class="mt-8 flex justify-center">
                {{ $albums->links() }}
            </div>
        @endif
    @endif
</x-app-layout>
