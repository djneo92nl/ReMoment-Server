<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Files" subtitle="Your own music: browse, import, locations and which source leads" :back="route('settings.index')" />
    </x-slot>

    @php($tabs = ['files' => ['Files', 'fa-folder-tree'], 'import' => ['Import', 'fa-cloud-arrow-up'], 'locations' => ['Locations & tasks', 'fa-hard-drive'], 'library' => ['Library', 'fa-compact-disc']])
    <div class="max-w-6xl space-y-6"
         x-data="{ tab: (new URLSearchParams(location.search).get('tab') || 'files'), pick(t) { this.tab = t; const u = new URL(location); u.searchParams.set('tab', t); history.replaceState(null, '', u); } }">
        <div class="flex flex-wrap gap-1 p-1 bg-gray-100 dark:bg-stone-800/60 rounded-2xl w-fit" role="tablist">
            @foreach($tabs as $key => [$label, $icon])
                <button type="button" role="tab" @click="pick('{{ $key }}')" data-test="tab-{{ $key }}"
                        :class="tab === '{{ $key }}' ? 'bg-white dark:bg-stone-900 shadow text-gray-900 dark:text-gray-100' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                        class="px-4 py-2 text-sm font-medium rounded-xl transition-colors">
                    <i class="fa-solid {{ $icon }} mr-1.5"></i>{{ $label }}
                </button>
            @endforeach
        </div>

        <div x-show="tab === 'files'"><livewire:storage-files /></div>
        <div x-show="tab === 'import'" x-cloak class="space-y-6">
            <livewire:storage-manager section="import" />
            <livewire:library-uploader />
        </div>
        <div x-show="tab === 'locations'" x-cloak><livewire:storage-manager /></div>
        <div x-show="tab === 'library'" x-cloak class="max-w-2xl">@include('settings.partials.library-form')</div>
    </div>
</x-app-layout>
