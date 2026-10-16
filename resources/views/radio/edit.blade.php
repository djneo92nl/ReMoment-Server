<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$radio->name" subtitle="Edit radio station" :back="route('radio.index')" />
    </x-slot>

    <div class="max-w-2xl">
        <x-card class="p-8 md:p-10">
            <form method="POST" action="{{ route('radio.update', $radio) }}">
                @csrf @method('PATCH')

                @include('radio.partials.form', ['radio' => $radio])

                <div class="mt-8 pt-6 border-t border-gray-100 dark:border-stone-800 flex items-center justify-between gap-3">
                    <button type="submit" form="delete-radio-form"
                            class="px-5 py-2.5 text-sm font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 transition-colors">
                        <i class="fa-solid fa-trash mr-1.5"></i>Remove
                    </button>
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

            <form id="delete-radio-form" method="POST" action="{{ route('radio.destroy', $radio) }}"
                  onsubmit="return confirm('Remove {{ addslashes($radio->name) }}?')">
                @csrf @method('DELETE')
            </form>
        </x-card>
    </div>
</x-app-layout>
