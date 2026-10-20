<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$definition['title']" :subtitle="$definition['description']" :back="route('settings.library-health')" />
    </x-slot>

    <div class="max-w-4xl space-y-4">
        @if($rows->isEmpty())
            <x-empty-state icon="circle-check" title="Nothing to report" text="No findings in this report." />
        @else
            <x-card flush>
                <ul class="divide-y divide-gray-100 dark:divide-stone-800">
                    @foreach($rows as $row)
                        <li class="px-5 py-3.5">
                            @if($row['url'])
                                <a href="{{ $row['url'] }}" class="text-sm font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $row['title'] }}</a>
                            @else
                                <p class="break-all text-sm font-medium text-gray-900 dark:text-gray-100">{{ $row['title'] }}</p>
                            @endif
                            <p class="mt-0.5 whitespace-pre-line break-all text-xs text-gray-500">{{ $row['detail'] }}</p>
                        </li>
                    @endforeach
                </ul>
            </x-card>
            @if($total > $rows->count())
                <p class="text-xs text-gray-400 dark:text-gray-600">Showing {{ $rows->count() }} of {{ number_format($total) }}.</p>
            @endif
        @endif
    </div>
</x-app-layout>
