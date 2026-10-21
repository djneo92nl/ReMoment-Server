<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Library health" subtitle="What the audio analysis and the file index found that needs a look" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-4xl space-y-6">
        <x-card compact :href="route('settings.analysis')" class="px-5 py-4" data-test="analysis-coverage">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm">
                <span class="text-gray-900 dark:text-gray-100">
                    <i class="fa-solid fa-wave-square mr-2 text-gray-400"></i>Analysed {{ number_format($analysis['complete']) }} of {{ number_format($analysis['files']) }} own files
                </span>
                <span class="text-gray-500">
                    @if(!$analysis['enabled']) analysis is off, so findings stay empty
                    @elseif($analysis['backlog'] > 0) {{ number_format($analysis['backlog']) }} still waiting: a 0 below may only mean "not looked at yet"
                    @else everything looked at @endif
                    &rsaquo;
                </span>
            </div>
        </x-card>
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
