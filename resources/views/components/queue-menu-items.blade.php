@props(['type', 'id', 'label'])
{{-- "Play next" / "Add to queue" entries for x-menu, on the pinned player and only when it reports queue_next / queue_add.
     Posts to devices.queue.add (type track|album|artist|playlist). Renders nothing without a pinned player. --}}
@php
    $pinned = app(\App\Support\SelectedDevice::class)->device();
    $caps = $pinned ? \App\Domain\Device\DeviceCapabilities::for($pinned) : [];
@endphp
@if($pinned)
    @foreach([['queue_next', 'Play next', true, 'forward-step'], ['queue_add', 'Add to queue', false, 'list-ol']] as [$capability, $text, $next, $icon])
        @if(in_array($capability, $caps, true))
            <form method="POST" action="{{ route('devices.queue.add', $pinned) }}">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="id" value="{{ $id }}">
                <input type="hidden" name="label" value="{{ $label }}"><input type="hidden" name="next" value="{{ $next ? 1 : 0 }}">
                <x-menu.item type="submit" :icon="$icon" title="{{ $text }} on {{ $pinned->device_name }}" data-test="{{ $type }}-{{ $capability }}">{{ $text }}</x-menu.item>
            </form>
        @endif
    @endforeach
@endif
