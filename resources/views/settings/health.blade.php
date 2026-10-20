<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Health" subtitle="Background processes, external services, queue and device listeners" :back="route('settings.index')" />
    </x-slot>

    @php($tabs = ['services' => ['Services', 'fa-plug'], 'system' => ['System & devices', 'fa-server'], 'queue' => ['Queue', 'fa-list-check']])
    <div class="max-w-4xl space-y-6"
         x-data="{ tab: (new URLSearchParams(location.search).get('tab') || 'services'), pick(t) { this.tab = t; const u = new URL(location); u.searchParams.set('tab', t); history.replaceState(null, '', u); } }">
        <div class="flex flex-wrap gap-1 p-1 bg-gray-100 dark:bg-stone-800/60 rounded-2xl w-fit" role="tablist">
            @foreach($tabs as $key => [$label, $icon])
                <button type="button" role="tab" @click="pick('{{ $key }}')" data-test="tab-{{ $key }}"
                        :class="tab === '{{ $key }}' ? 'bg-white dark:bg-stone-900 shadow text-gray-900 dark:text-gray-100' : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                        class="px-4 py-2 text-sm font-medium rounded-xl transition-colors">
                    <i class="fa-solid {{ $icon }} mr-1.5"></i>{{ $label }}
                </button>
            @endforeach
        </div>

        <div x-show="tab === 'services'"><livewire:external-services-health /></div>
        <div x-show="tab === 'system'" x-cloak><livewire:system-health /></div>
        <div x-show="tab === 'queue'" x-cloak><livewire:queue-manager /></div>
    </div>
</x-app-layout>
