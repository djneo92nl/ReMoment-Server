@props(['live', 'mixtapes', 'art'])
{{-- NTS on /radio, in the page's own style: the two live channels as station rows on the left (40%), the infinite mixtapes on the right (60%):
     a row of covers to slide through, the picked one named underneath with a play button. --}}
<section x-data="{
        pick: @js($mixtapes->first()?->id),
        names: @js($mixtapes->mapWithKeys(fn ($s) => [$s->id => \Illuminate\Support\Str::after($s->name, 'NTS ')])),
        choose(id) { this.pick = id; this.$nextTick(() => this.$refs['m' + id]?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' })) },
        by(dir) { this.$refs.row.scrollBy({ left: dir * this.$refs.row.clientWidth * 0.8, behavior: 'smooth' }) },
    }">
    <h2 class="mb-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">
        <a href="{{ route('radio.nts') }}" class="inline-flex items-center gap-1.5 hover:underline">NTS<i class="fa-solid fa-chevron-right text-xs text-gray-400 dark:text-gray-600"></i></a>
    </h2>

    <div class="grid gap-x-8 gap-y-4 md:grid-cols-5">
        <div class="md:col-span-2">
            @foreach($live as $station)
                <x-radio-row :station="$station" :art="$art[$station->id]" live subtitle="Live" />
            @endforeach
        </div>

        @if($mixtapes->isNotEmpty())
            <div class="min-w-0 md:col-span-3">
                <div class="mb-2 flex items-center justify-between px-2">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Infinite Mixtapes</p>
                    <div class="flex gap-1.5">
                        <button type="button" @click="by(-1)" aria-label="Slide mixtapes back" class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-100 text-gray-500 transition hover:bg-gray-200 dark:bg-stone-800 dark:text-gray-400 dark:hover:bg-stone-700"><i class="fa-solid fa-chevron-left text-[10px]"></i></button>
                        <button type="button" @click="by(1)" aria-label="Slide mixtapes forward" class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-100 text-gray-500 transition hover:bg-gray-200 dark:bg-stone-800 dark:text-gray-400 dark:hover:bg-stone-700"><i class="fa-solid fa-chevron-right text-[10px]"></i></button>
                    </div>
                </div>

                <div x-ref="row" role="listbox" aria-label="Infinite Mixtapes" class="no-scrollbar flex snap-x gap-3 overflow-x-auto px-2 py-2">
                    @foreach($mixtapes as $station)
                        <button type="button" role="option" x-ref="m{{ $station->id }}" :aria-selected="pick === {{ $station->id }}" @click="choose({{ $station->id }})"
                                :class="pick === {{ $station->id }} ? 'ring-2 ring-gray-900 dark:ring-gray-100 ring-offset-2 ring-offset-gray-50 dark:ring-offset-stone-950' : 'opacity-60 hover:opacity-100'"
                                class="shrink-0 snap-center rounded-xl transition" title="{{ \Illuminate\Support\Str::after($station->name, 'NTS ') }}">
                            <x-artwork-thumb :src="$art[$station->id]['proxy_120'] ?? null" :colors="$art[$station->id]['colors'] ?? null" :seed="$station->name" :label="$station->initials()" data-label-class="text-sm" size="h-14 w-14" proxy="120" />
                        </button>
                    @endforeach
                </div>

                <div class="mt-1 flex items-center gap-2 px-2">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" x-text="names[pick]"></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Infinite Mixtape</p>
                    </div>
                    @foreach($mixtapes as $station)
                        <div x-show="pick === {{ $station->id }}" x-cloak><x-radio-play :station="$station" tone="plain" /></div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>
