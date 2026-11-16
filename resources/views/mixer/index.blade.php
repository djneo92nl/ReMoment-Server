<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Stem mixer" subtitle="Mix the vocals, drums, bass and the rest of two tracks, in this browser" />
    </x-slot>

    <div x-data="mixer({{ \Illuminate\Support\Js::from(['api' => url('/api'), 'tracks' => $tracks]) }})" class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-2">
            @include('mixer._deck', ['side' => 'a'])
            @include('mixer._deck', ['side' => 'b'])
        </div>

        <x-card class="p-5 sm:p-6">
            <div class="grid gap-5 sm:grid-cols-[1fr_auto]">
                <div>
                    <div class="flex items-center justify-between text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">
                        <span>Deck A</span><span>Crossfader</span><span>Deck B</span>
                    </div>
                    <div class="flex items-center">
                        @include('mixer._slider', ['value' => 'cross', 'min' => '0', 'max' => '1', 'step' => '0.01', 'input' => 'cross = Number($event.target.value); applyCross()', 'label' => "'Crossfader'"])
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <x-secondary-button type="button" size="md" @click="playBoth()"><i class="fa-solid fa-play text-xs"></i>Play or pause both</x-secondary-button>
                </div>
            </div>
            <div class="mt-3 flex items-center gap-3">
                <i class="fa-solid fa-volume-high w-4 flex-shrink-0 text-gray-400 dark:text-gray-600"></i>
                @include('mixer._slider', ['value' => 'master', 'min' => '0', 'max' => '1', 'step' => '0.01', 'input' => 'setMaster($event.target.value)', 'label' => "'Master volume'"])
                <span class="w-9 text-right text-xs tabular-nums text-gray-500" x-text="percent(master)"></span>
            </div>
        </x-card>

        <x-card class="p-5 sm:p-6">
            <x-section-heading title="Pick a track" />
            <x-text-input type="search" class="w-full" placeholder="Search tracks (two letters or more)" x-model="query" @input.debounce.350ms="search()" aria-label="Search tracks" />
            <p class="mt-3 text-sm text-red-500" x-show="pickerError" x-text="pickerError"></p>

            <template x-if="found.length">
                <div class="mt-4">
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Results</p>
                    @include('mixer._rows', ['rows' => 'found'])
                </div>
            </template>

            <template x-if="similar.length">
                <div class="mt-5">
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Mixes well with <span class="normal-case" x-text="deck.a.track?.name"></span></p>
                    @include('mixer._rows', ['rows' => 'similar'])
                </div>
            </template>

            <p class="mt-4 text-xs text-gray-400 dark:text-gray-600" x-show="!found.length && !similar.length">Search for a track, or load one into deck A to see what mixes well with it (same tempo and a neighbouring key).</p>
        </x-card>
    </div>
</x-app-layout>
