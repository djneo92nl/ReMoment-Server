<x-app-layout>
    <x-slot name="header">
        <div class="flex items-end justify-between gap-4 flex-wrap">
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Albums</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">{{ number_format($albums->total()) }} {{ Str::plural('album', $albums->total()) }}{{ $search !== '' ? ' matching “'.$search.'”' : '' }}</p>
            </div>
            <x-library-scope :scope="$scope" />
        </div>
    </x-slot>

    <form method="GET" action="{{ route('albums.index') }}" class="mb-6 flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-[12rem] max-w-sm">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
            <input type="search" name="q" value="{{ $search }}" placeholder="Search albums or artists"
                   class="w-full pl-8 rounded-xl border-gray-200 dark:border-stone-700 dark:bg-stone-900 text-sm focus:ring-indigo-500 focus:border-indigo-500">
        </div>
        <select name="sort" onchange="this.form.submit()"
                class="rounded-xl border-gray-200 dark:border-stone-700 dark:bg-stone-900 text-sm text-gray-600 dark:text-gray-400 focus:ring-indigo-500 focus:border-indigo-500">
            <option value="recent" @selected($sort === 'recent')>Recently added</option>
            <option value="plays" @selected($sort === 'plays')>Most played</option>
            <option value="year" @selected($sort === 'year')>Release year</option>
            <option value="name" @selected($sort === 'name')>Album A–Z</option>
            <option value="artist" @selected($sort === 'artist')>Artist A–Z</option>
            <option value="rating" @selected($sort === 'rating')>Highest rated</option>
            <option value="listeners" @selected($sort === 'listeners')>Most listened (Last.fm)</option>
        </select>
        @php $selectClass = 'rounded-xl border-gray-200 dark:border-stone-700 dark:bg-stone-900 text-sm text-gray-600 dark:text-gray-400 focus:ring-indigo-500 focus:border-indigo-500'; @endphp
        @if($filters['genres']->isNotEmpty())
            <select name="genre" onchange="this.form.submit()" class="{{ $selectClass }}">
                <option value="">All genres</option>
                @foreach($filters['genres'] as $g)
                    <option value="{{ $g->slug }}" @selected($filters['genre'] === $g->slug)>{{ $g->name }}</option>
                @endforeach
            </select>
        @endif
        @if($filters['moods'])
            <select name="mood" onchange="this.form.submit()" class="{{ $selectClass }}">
                <option value="">Any mood</option>
                @foreach($filters['moods'] as $m)
                    <option value="{{ $m }}" @selected($filters['mood'] === $m)>{{ ucfirst($m) }}</option>
                @endforeach
            </select>
        @endif
        @if($filters['tempos'])
            <select name="tempo" onchange="this.form.submit()" class="{{ $selectClass }}">
                <option value="">Any tempo</option>
                @foreach(['slow' => 'Slow (under 90 BPM)', 'steady' => 'Steady (90–125)', 'fast' => 'Fast (over 125)'] as $key => $label)
                    @if(in_array($key, $filters['tempos'], true))
                        <option value="{{ $key }}" @selected($filters['tempo'] === $key)>{{ $label }}</option>
                    @endif
                @endforeach
            </select>
        @endif
        <label class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 px-2">
            <input type="checkbox" name="fav" value="1" @checked($favorites) onchange="this.form.submit()" class="rounded text-indigo-600 focus:ring-indigo-500">
            Favorites
        </label>
        <button type="submit" class="sr-only">Apply</button>
    </form>

    @if($albums->isEmpty())
        <div class="bg-white dark:bg-stone-900 rounded-2xl border border-gray-200/70 dark:border-stone-800/80 p-12 text-center">
            <i class="fa-solid fa-compact-disc text-3xl text-gray-200 dark:text-stone-700 mb-3"></i>
            <p class="text-gray-400 dark:text-gray-600 text-sm">No albums here{{ ($search !== '' || $favorites || $filters['genre'] !== '' || $filters['mood'] !== '' || $filters['tempo'] !== '') ? ' for these filters' : ' yet' }}.</p>
        </div>
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
