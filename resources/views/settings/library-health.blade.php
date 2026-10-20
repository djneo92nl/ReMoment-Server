<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Library health" subtitle="What the audio analysis and the file index found that needs a look" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-4xl space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach($definitions as $key => $definition)
                @php($count = $counts[$key] ?? 0)
                <x-card compact :href="route('settings.library-health.show', $key)" class="p-5" data-test="report-{{ $key }}">
                    <div class="flex items-start justify-between gap-4">
                        <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">{{ $definition['title'] }}</h2>
                        <span class="text-2xl font-medium {{ $count > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ number_format($count) }}</span>
                    </div>
                    <p class="mt-1.5 text-sm text-gray-500">{{ $definition['description'] }}</p>
                </x-card>
            @endforeach
        </div>
        <p class="text-xs text-gray-400 dark:text-gray-600">Counts refresh every few minutes. Analysis findings need <code>ANALYSIS_ENABLED</code> and fill in as the nightly analysis works through the library.</p>
    </div>
</x-app-layout>
