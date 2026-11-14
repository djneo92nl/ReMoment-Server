<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$sceneId ? 'Edit scene' : 'New scene'" :back="route('scenes.index')" />
    </x-slot>

    <div class="max-w-3xl">
        <livewire:scene-editor :scene-id="$sceneId" />
    </div>
</x-app-layout>
