@php
    $profile = \App\Domain\Profile\Profile::class;
    $title = $feature ? config('profile.features.'.$feature.'.label') : 'This feature';
    $text = $feature ? $profile::unavailableMessage($feature) : 'The '.$profile::label().' profile does not run it.';
@endphp
<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Not part of your profile" :subtitle="'This server runs the '.$profile::label().' profile'" />
    </x-slot>

    <div class="max-w-2xl">
        <x-empty-state icon="layer-group" :title="$title" :text="$text">
            <div class="flex flex-wrap justify-center gap-3">
                <x-primary-button size="md" href="{{ route('devices.index') }}">Back to devices</x-primary-button>
            </div>
        </x-empty-state>
    </div>
</x-app-layout>
