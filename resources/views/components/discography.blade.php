@props(['albums', 'source' => null, 'limit' => 12])
{{-- An artist with many albums (DESIGN.md §10): chips per kind with counts, a search box and a sort, then one grid per kind (Albums, Singles & EPs,
     Live, Compilations, Editions & pressings; AlbumKind reads the name, as libraries hold every pressing as its own album). Filtering, searching and sorting
     happen in the browser over the rendered cards; each kind shows its first `limit` until "Show all". --}}
@php
    $kinds = \App\Domain\Library\AlbumKind::LABELS;
    $items = $albums->map(fn ($album) => [
        'id' => $album->id,
        'k' => \App\Domain\Library\AlbumKind::of($album, $album->tracks->count()),
        'name' => mb_strtolower($album->name),
        'year' => $album->released_at?->format('Y'),
        'album' => $album,
    ]);

    // Rank of each album within its kind for every sort, so a card's CSS `order` is all the browser needs to re-sort.
    $rank = ['new' => [], 'old' => [], 'az' => []];
    foreach ($items->groupBy('k') as $group) {
        $by = [
            'new' => $group->sortBy([fn ($a, $b) => ($b['album']->released_at?->timestamp ?? 0) <=> ($a['album']->released_at?->timestamp ?? 0), fn ($a, $b) => $a['name'] <=> $b['name']]),
            'old' => $group->sortBy([fn ($a, $b) => ($a['album']->released_at?->timestamp ?? PHP_INT_MAX) <=> ($b['album']->released_at?->timestamp ?? PHP_INT_MAX), fn ($a, $b) => $a['name'] <=> $b['name']]),
            'az' => $group->sortBy('name'),
        ];
        foreach ($by as $sort => $sorted) {
            foreach ($sorted->values() as $i => $item) {
                $rank[$sort][$item['id']] = $i;
            }
        }
    }
    $counts = $items->countBy('k');
    $shown = collect($kinds)->filter(fn ($label, $k) => ($counts[$k] ?? 0) > 0);
    $data = $items->map(fn ($i) => ['id' => $i['id'], 'k' => $i['k'], 'name' => $i['name']])->values();
@endphp

<section x-data="{ kind: 'all', q: '', sort: 'new', open: {}, limit: {{ $limit }}, items: @js($data), rank: @js($rank),
        has(k, id, name) { return (this.kind === 'all' || this.kind === k) && (this.q === '' || name.includes(this.q.trim().toLowerCase())) },
        count(k) { return this.items.filter(i => i.k === k && this.has(i.k, i.id, i.name)).length },
        visible(k, id, name) { return this.has(k, id, name) && (this.q !== '' || this.kind !== 'all' || this.open[k] || this.rank[this.sort][id] < this.limit) } }">
    <h2 class="mb-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">Albums <span class="text-sm font-normal text-gray-400">· {{ $items->count() }}</span></h2>

    <div class="mb-5 flex flex-wrap items-center gap-3">
        <div class="no-scrollbar -mx-1 flex min-w-0 gap-1 overflow-x-auto px-1" role="group" aria-label="Kind">
            <button type="button" @click="kind = 'all'" :class="kind === 'all' ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-stone-800 dark:text-gray-400 dark:hover:bg-stone-700'"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors">All <span class="text-xs opacity-60">{{ $items->count() }}</span></button>
            @foreach($shown as $k => $label)
                <button type="button" @click="kind = '{{ $k }}'" data-test="kind-{{ $k }}" :class="kind === '{{ $k }}' ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-stone-800 dark:text-gray-400 dark:hover:bg-stone-700'"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors">{{ $label }} <span class="text-xs opacity-60">{{ $counts[$k] }}</span></button>
            @endforeach
        </div>
        <div class="ml-auto flex items-center gap-2">
            <x-text-input type="search" x-model="q" placeholder="Find an album" aria-label="Find an album" class="w-44" />
            <x-select x-model="sort" aria-label="Sort albums">
                <option value="new">Newest first</option>
                <option value="old">Oldest first</option>
                <option value="az">A to Z</option>
            </x-select>
        </div>
    </div>

    <div class="space-y-8">
        @foreach($shown as $k => $label)
            <div x-show="count('{{ $k }}') > 0" @if(! $loop->first) x-cloak @endif>
                <div class="mb-3 flex items-baseline justify-between gap-4" x-show="kind === 'all'">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">{{ $label }} <span class="font-normal normal-case" x-text="'· ' + count('{{ $k }}')"></span></h3>
                    <button type="button" x-show="q === '' && ! open['{{ $k }}'] && count('{{ $k }}') > limit" @click="open['{{ $k }}'] = true" class="text-sm text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">Show all {{ $counts[$k] }}</button>
                </div>
                <x-album-grid>
                    @foreach($items->where('k', $k) as $item)
                        <div x-show="visible('{{ $k }}', {{ $item['id'] }}, @js($item['name']))" :style="`order:${rank[sort][{{ $item['id'] }}]}`" class="min-w-0">
                            <x-album-card :album="$item['album']" :source="$source" />
                        </div>
                    @endforeach
                </x-album-grid>
            </div>
        @endforeach
        <p x-show="items.every(i => ! has(i.k, i.id, i.name))" x-cloak class="text-sm text-gray-500">No album matches.</p>
    </div>
</section>
