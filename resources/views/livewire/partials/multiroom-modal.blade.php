    {{-- ── Multiroom picker: tiles for the rooms, a check marks who is in the session (B&O style) ── --}}
        <x-modal name="multiroom-{{ $device->id }}" maxWidth="md">
            @include('livewire.partials.multiroom-content')
        </x-modal>
