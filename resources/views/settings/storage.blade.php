<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back-button href="{{ route('settings.index') }}" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">File Storage</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Where your own music files live (see docs/architecture/file-storage.md)</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl space-y-6">
        <livewire:storage-manager />
        <livewire:storage-files />
    </div>
</x-app-layout>
