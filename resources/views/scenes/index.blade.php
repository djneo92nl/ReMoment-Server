<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Scenes" subtitle="Run a list of actions with one tap, now or on a schedule">
            <x-slot name="actions">
                <a href="{{ route('scenes.create') }}"><x-secondary-button type="button"><i class="fa-solid fa-plus text-xs"></i>New scene</x-secondary-button></a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <livewire:scene-list />
</x-app-layout>
