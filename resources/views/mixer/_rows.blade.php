{{-- Track rows of the picker. $rows is the name of the Alpine list (found | similar). A track loads into deck A or B. --}}
<ul class="divide-y divide-gray-200/70 dark:divide-stone-800">
    <template x-for="row in {{ $rows }}" :key="row.id">
        <li class="flex items-center gap-3 py-2">
            <div class="h-10 w-10 flex-none overflow-hidden rounded-xl bg-gray-100 dark:bg-stone-800">
                <img x-show="row.artwork?.proxy_120" :src="row.artwork?.proxy_120" alt="" class="h-full w-full object-cover" loading="lazy">
            </div>
            <div class="min-w-0 flex-1">
                <p class="flex items-center gap-2 text-sm text-gray-900 dark:text-gray-100"><span class="truncate" x-text="row.name"></span><x-badge variant="success" x-show="row.has_stems" x-cloak title="The stems of this track are made and kept">Stems</x-badge></p>
                <p class="truncate text-xs text-gray-500" x-text="[row.artist_name, row.bpm ? Math.round(row.bpm) + ' BPM' : null, row.camelot, row.tempo_relation && row.tempo_relation !== 'same' ? row.tempo_relation + ' time' : null].filter(Boolean).join(' · ')"></p>
            </div>
            <x-secondary-button size="sm" @click="setTrack('a', row)" aria-label="Load into deck A">A</x-secondary-button>
            <x-secondary-button size="sm" @click="setTrack('b', row)" aria-label="Load into deck B">B</x-secondary-button>
        </li>
    </template>
</ul>
