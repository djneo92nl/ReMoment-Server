<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('devices.index') }}"
               class="flex items-center justify-center w-9 h-9 rounded-xl bg-gray-100 dark:bg-stone-800 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-stone-700 transition-colors flex-shrink-0">
                <i class="fa-solid fa-arrow-left text-sm"></i>
            </a>
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Add Device</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Register a new networked device</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl">
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8 md:p-10">
            <form method="POST" action="{{ route('devices.store') }}">
                @csrf

                @include('devices.partials.form', ['device' => null])

                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-stone-800 flex items-center justify-end gap-3">
                    <a href="{{ route('devices.index') }}"
                       class="px-5 py-2.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 transition-colors">
                        Cancel
                    </a>
                    <button type="submit"
                            class="px-6 py-2.5 bg-gray-900 dark:bg-stone-700 text-white rounded-2xl text-sm font-medium hover:bg-gray-700 dark:hover:bg-stone-600 transition-colors">
                        <i class="fa-solid fa-plus mr-2"></i>Add Device
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
