<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <x-back-button href="{{ route('devices.show', $device) }}" />
                <div>
                    <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Edit Device</h1>
                    <p class="mt-1.5 text-gray-500 dark:text-gray-500">{{ $device->device_name }}</p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl">
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8 md:p-10">
            <form method="POST" action="{{ route('devices.update', $device) }}">
                @csrf
                @method('PATCH')

                @include('devices.partials.form', ['driverConfig' => $driverConfig])

                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-stone-800 flex items-center justify-end gap-3">
                    <a href="{{ route('devices.show', $device) }}"
                       class="px-5 py-2.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 transition-colors">
                        Cancel
                    </a>
                    <x-primary-button size="xl">
                        <i class="fa-solid fa-floppy-disk"></i>Save Changes
                    </x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
