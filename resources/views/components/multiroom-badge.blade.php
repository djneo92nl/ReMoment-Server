@props(['device'])

@php
    $status = \App\Domain\Device\Cache\MultiRoom::get($device->id)?->resolve($device);
@endphp

@if($status && $status['role'] === 'listener')
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-xs text-sky-600 dark:text-sky-400']) }}
          title="Listening to {{ $status['host']['name'] ?? 'another device' }}">
        <i class="fa-solid fa-link"></i>
        <span class="truncate max-w-32">{{ $status['host']['name'] ?? 'Joined' }}</span>
    </span>
@endif
