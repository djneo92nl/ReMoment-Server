<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Add Device" subtitle="Register a new networked device" :back="route('devices.index')" />
    </x-slot>

    <div class="max-w-2xl">
        <x-card class="p-8 md:p-10">
            <form method="POST" action="{{ route('devices.store') }}">
                @csrf

                @include('devices.partials.form', ['device' => null])

                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-stone-800 flex items-center justify-end gap-3">
                    <a href="{{ route('devices.index') }}"
                       class="px-5 py-2.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 transition-colors">
                        Cancel
                    </a>
                    <x-primary-button size="xl">
                        <i class="fa-solid fa-plus"></i>Add Device
                    </x-primary-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
