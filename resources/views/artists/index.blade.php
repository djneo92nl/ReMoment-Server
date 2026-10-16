<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Artists" :subtitle="number_format($artists->total()).' '.Str::plural('artist', $artists->total()).($letter ? ' under '.$letter : '')">
            <x-slot name="actions"><x-library-scope :scope="$scope" /></x-slot>
        </x-page-header>
    </x-slot>

    @php
        $keep = fn (array $extra) => array_filter(array_merge(request()->only(['q', 'sort', 'genre', 'fav']), $extra), fn ($v) => $v !== null && $v !== '');
    @endphp

    <form method="GET" action="{{ route('artists.index') }}" class="mb-4 flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-[12rem] max-w-sm">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
            <x-text-input type="search" name="q" value="{{ $search }}" placeholder="Search artists" class="w-full pl-8" />
        </div>
        <x-select name="genre" onchange="this.form.submit()">
            <option value="">All genres</option>
            @foreach($genres as $g)
                <option value="{{ $g->slug }}" @selected($genre?->slug === $g->slug)>{{ $g->name }}</option>
            @endforeach
        </x-select>
        <x-select name="sort" onchange="this.form.submit()">
            <option value="name" @selected($sort === 'name')>A–Z</option>
            <option value="plays" @selected($sort === 'plays')>Most played</option>
            <option value="albums" @selected($sort === 'albums')>Most albums</option>
        </x-select>
        <label class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 px-2">
            <input type="checkbox" name="fav" value="1" @checked($favorites) onchange="this.form.submit()" class="rounded text-gray-900 dark:text-stone-500 focus:ring-gray-400">
            Favorites
        </label>
        <button type="submit" class="sr-only">Apply</button>
    </form>

    {{-- A–Z index --}}
    @if($sort === 'name' && count($letters) > 0)
        <nav class="sticky top-14 md:top-0 z-20 -mx-1 mb-5 px-1 py-2 bg-gray-50/90 dark:bg-stone-950/90 backdrop-blur flex flex-wrap gap-1 text-xs" aria-label="Alphabet">
            <a href="{{ route('artists.index', $keep(['letter' => null])) }}"
               @class(['px-2 py-1 rounded-xl font-medium', 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' => !$letter, 'text-gray-500 hover:bg-gray-200 dark:hover:bg-stone-800' => $letter])>All</a>
            @foreach(array_merge(['#'], range('A', 'Z')) as $l)
                @if(isset($letters[$l]))
                    <a href="{{ route('artists.index', $keep(['letter' => $l])) }}" title="{{ $letters[$l] }}"
                       @class(['px-2 py-1 rounded-xl font-medium', 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' => $letter === $l, 'text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-stone-800' => $letter !== $l])>{{ $l }}</a>
                @else
                    <span class="px-2 py-1 text-gray-300 dark:text-stone-700">{{ $l }}</span>
                @endif
            @endforeach
        </nav>
    @endif

    @if($artists->isEmpty())
        <x-empty-state icon="microphone-lines" :text="'No artists here'.(($search !== '' || $favorites || $genre || $letter) ? ' for these filters' : ' yet').'.'" />
    @else
        @php
            $groups = $sort === 'name'
                ? $artists->getCollection()->groupBy(function ($a) {
                    $first = strtoupper(mb_substr(preg_replace('/^the\s+/i', '', $a->name), 0, 1));
                    return preg_match('/^[A-Z]$/', $first) ? $first : '#';
                })
                : collect(['' => $artists->getCollection()]);
        @endphp

        <div class="space-y-6">
            @foreach($groups as $heading => $group)
                <section>
                    @if($heading !== '')
                        <h2 class="mb-2 text-sm font-semibold text-gray-400 dark:text-gray-600 tracking-wider">{{ $heading }}</h2>
                    @endif
                    <x-album-grid>
                        @foreach($group as $artist)
                            <x-artist-card :artist="$artist" />
                        @endforeach
                    </x-album-grid>
                </section>
            @endforeach
        </div>

        @if($artists->hasPages())
            <div class="mt-8 flex justify-center">
                {{ $artists->links() }}
            </div>
        @endif
    @endif
</x-app-layout>
