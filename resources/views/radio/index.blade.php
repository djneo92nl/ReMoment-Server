<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Radio" :subtitle="$total.' '.Str::plural('station', $total)">
            <x-slot name="actions">
                <x-primary-button href="{{ route('radio.create') }}">
                    <i class="fa-solid fa-plus"></i>
                    Add station
                </x-primary-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-10">
        @if($total === 0)
            <x-empty-state icon="radio" title="No radio stations yet" text="Search the radio directory, or add NTS with one click">
                <x-primary-button href="{{ route('radio.create') }}"><i class="fa-solid fa-plus"></i>Add station</x-primary-button>
            </x-empty-state>
        @endif

        @if(!empty($heard))
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Heard lately</span>
                @foreach($heard as $h)
                    <x-chip href="{{ route('radio.show', $h['station_id']) }}">{{ $h['artist'] }} <span class="text-xs text-gray-400 dark:text-gray-600">{{ $h['station'] }}</span></x-chip>
                @endforeach
            </div>
        @endif

        {{-- Favourites: live titles on the first dozen --}}
        @if($favourites->isNotEmpty())
            <x-shelf title="Favourites">
                @foreach($favourites as $station)
                    <x-radio-card :station="$station" :art="$art[$station->id]" :subtitle="$station->genre" :live="$loop->index < 12" />
                @endforeach
            </x-shelf>
        @endif

        {{-- NTS: its own block, live channels and the infinite mixtapes behind a switch --}}
        @if($nts->isNotEmpty())
            <x-nts-panel :live="$nts->filter(fn ($s) => $s->getMeta('nts_channel') !== null)->values()" :mixtapes="$nts->filter(fn ($s) => $s->getMeta('nts_channel') === null)->values()" :art="$art" />
        @elseif($ntsOn && $total > 0)
            <x-card compact class="flex flex-wrap items-center justify-between gap-3 p-4">
                <p class="text-sm text-gray-500 dark:text-gray-400">NTS Radio: two live channels, 16 mixtapes and the show archive.</p>
                <form method="POST" action="{{ route('radio.import-nts') }}">
                    @csrf
                    <x-secondary-button type="submit"><i class="fa-solid fa-plus"></i> Add NTS</x-secondary-button>
                </form>
            </x-card>
        @endif

        {{-- Everything else: a list with a filter, not a wall of cards --}}
        @if($others->isNotEmpty())
            @php $genres = $others->pluck('genre')->filter()->countBy()->sortDesc()->keys()->take(8); @endphp
            <section x-data="{ q: '', genre: null }">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">Stations</h2>
                    @if($others->count() > 8)
                        <div class="relative w-full sm:w-64">
                            <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-gray-300 dark:text-stone-600"></i>
                            <x-text-input type="search" x-model="q" placeholder="Filter stations" aria-label="Filter stations" class="w-full pl-10" />
                        </div>
                    @endif
                </div>

                @if($genres->count() > 1)
                    <div class="mb-3 flex flex-wrap gap-2">
                        @foreach($genres as $g)
                            <button type="button" @click="genre = genre === @js($g) ? null : @js($g)"
                                    :class="genre === @js($g) ? 'bg-gray-900 text-white dark:bg-stone-700' : 'bg-white text-gray-600 dark:bg-stone-900 dark:text-gray-400'"
                                    class="rounded-full border border-gray-200/70 px-3 py-1 text-xs transition-colors dark:border-stone-800">{{ $g }}</button>
                        @endforeach
                    </div>
                @endif

                <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($others as $station)
                        <div x-show="(!genre || genre === @js($station->genre)) && (!q || @js(mb_strtolower($station->name)).includes(q.toLowerCase()))">
                            <x-radio-row :station="$station" :art="$art[$station->id]" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
