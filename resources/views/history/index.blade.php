<x-app-layout>
    <x-slot name="header">
        <x-page-header title="History" subtitle="All played tracks, radio stations and sources" />
    </x-slot>

    <livewire:play-history />

    @auth
        @php($keepMonths = \App\Domain\Stats\PlayRetention::months())
        <x-card class="mt-10 p-5">
            <form method="POST" action="{{ route('settings.history.save') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="min-w-48 flex-1">
                    <label for="keep_months" class="block text-sm font-medium text-gray-900 dark:text-gray-100">Keep history for</label>
                    <p class="text-xs text-gray-500">Empty keeps every play forever. Older plays are deleted every night; your tracks stay.</p>
                </div>
                <x-text-input id="keep_months" name="keep_months" type="number" min="1" max="{{ \App\Domain\Stats\PlayRetention::MAX_MONTHS }}" value="{{ $keepMonths }}" placeholder="forever" size="sm" class="w-28" />
                <span class="pb-2 text-xs text-gray-500">months</span>
                <x-primary-button>Save</x-primary-button>
            </form>
            @if(session('success'))
                <p class="mt-3 text-xs text-emerald-600 dark:text-emerald-400">{{ session('success') }}</p>
            @endif
        </x-card>
    @endauth
</x-app-layout>
