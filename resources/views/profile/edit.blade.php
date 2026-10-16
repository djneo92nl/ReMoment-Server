<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Profile" subtitle="Change the admin password" />
    </x-slot>

    <div class="max-w-xl">
        <x-card class="p-8">
            @include('profile.partials.update-password-form')
        </x-card>
    </div>
</x-app-layout>
