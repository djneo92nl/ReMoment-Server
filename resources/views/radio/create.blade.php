<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Add Radio Station" subtitle="Register a known station for cross-device playback" :back="route('radio.index')" />
    </x-slot>

    <div class="max-w-2xl space-y-6">
        <x-card class="p-8 md:p-10">
            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-4">Find a station</p>
            <livewire:radio-station-search />
            <p class="mt-4 text-xs text-gray-400 dark:text-gray-600">
                <a href="{{ route('radio.index') }}" class="underline">Done adding? Back to radio.</a>
            </p>
        </x-card>

        <x-card class="p-8 md:p-10">
            <form method="POST" action="{{ route('radio.store') }}">
                @csrf

                @include('radio.partials.form')

                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-stone-800 flex items-center justify-end gap-3">
                    <a href="{{ route('radio.index') }}"
                       class="px-5 py-2.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 transition-colors">
                        Cancel
                    </a>
                    <x-primary-button size="xl">
                        <i class="fa-solid fa-plus"></i>Add Station
                    </x-primary-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
