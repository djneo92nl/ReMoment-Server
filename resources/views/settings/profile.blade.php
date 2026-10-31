@php
    $features = config('profile.features');
    $profiles = config('profile.profiles');
@endphp
<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Profile" subtitle="What this server runs: Control, Standard or Full" back="{{ route('settings.index') }}" />
    </x-slot>

    <div class="max-w-5xl space-y-8">
        <livewire:profile-picker />

        <x-alert type="info">
            The profile is read from <code>PROFILE</code> in <code>.env</code> when the containers start. Changing it also changes which containers run
            (MariaDB, the analysis sidecars), so it needs a restart of the stack; see <code>docs/architecture/profiles.md</code>.
        </x-alert>

        <div>
            <x-section-heading title="What each profile runs" />
            <x-table data-test="capability-table">
                <x-slot name="head">
                    <x-table.th>Feature</x-table.th>
                    @foreach($profiles as $profile)
                        <x-table.th>{{ $profile['label'] }}</x-table.th>
                    @endforeach
                </x-slot>
                @foreach($features as $key => $feature)
                    <tr>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $feature['label'] }}</td>
                        @foreach($profiles as $name => $profile)
                            <td class="px-4 py-3">
                                @if(in_array($name, $feature['profiles'], true))
                                    <i class="fa-solid fa-check text-emerald-500" aria-label="Yes"></i>
                                @else
                                    <span class="text-gray-300 dark:text-stone-600" aria-label="No">&mdash;</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </x-table>
        </div>
    </div>
</x-app-layout>
