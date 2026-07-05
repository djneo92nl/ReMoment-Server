<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back-button href="{{ route('radio.index') }}" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">{{ $radio->name }}</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">Edit radio station</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl">
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8 md:p-10">
            <form method="POST" action="{{ route('radio.update', $radio) }}">
                @csrf @method('PATCH')

                @include('radio.partials.form', ['radio' => $radio])

                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-stone-800 flex items-center justify-between gap-3">
                    <form method="POST" action="{{ route('radio.destroy', $radio) }}"
                          onsubmit="return confirm('Remove {{ addslashes($radio->name) }}?')">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="px-5 py-2.5 text-sm font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 transition-colors">
                            <i class="fa-solid fa-trash mr-1.5"></i>Remove
                        </button>
                    </form>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('radio.index') }}"
                           class="px-5 py-2.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 transition-colors">
                            Cancel
                        </a>
                        <x-primary-button size="xl">
                            Save Changes
                        </x-primary-button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
