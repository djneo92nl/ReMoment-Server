<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Edit Device" :subtitle="$device->device_name" :back="request('from') === 'settings' ? route('settings.devices') : route('devices.show', $device)" />
    </x-slot>

    <div class="max-w-2xl">
        <x-card class="p-8 md:p-10">
            <form method="POST" action="{{ route('devices.update', $device) }}">
                @csrf
                @if(request('from') === 'settings')<input type="hidden" name="from" value="settings">@endif
                @method('PATCH')

                @include('devices.partials.form', ['driverConfig' => $driverConfig])

                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-stone-800 flex items-center justify-end gap-3">
                    <a href="{{ request('from') === 'settings' ? route('settings.devices') : route('devices.show', $device) }}"
                       class="px-5 py-2.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 transition-colors">
                        Cancel
                    </a>
                    <x-primary-button size="xl">
                        <i class="fa-solid fa-floppy-disk"></i>Save Changes
                    </x-primary-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
