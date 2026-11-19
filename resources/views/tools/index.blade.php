<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Tools" subtitle="Things to do with your music and sound" />
    </x-slot>

    @if($tools === [])
        <x-empty-state icon="screwdriver-wrench" title="No tools in this profile" text="Tools such as the stem mixer need the Full profile." />
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-test="tools">
            @foreach($tools as $tool)
                <x-card compact :href="route($tool['route'])" class="p-5" data-tool="{{ $tool['key'] }}">
                    <div class="flex items-center gap-4">
                        <div class="flex h-12 w-12 flex-none items-center justify-center rounded-2xl bg-gray-100 dark:bg-stone-800">
                            <i class="fa-solid fa-{{ $tool['icon'] }} text-gray-600 dark:text-gray-300"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $tool['label'] }}</p>
                            <p class="mt-0.5 text-sm text-gray-500">{{ $tool['description'] }}</p>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</x-app-layout>
