<div wire:poll.30s class="{{ $tinted ? 'tint-muted mt-0.5' : 'text-gray-500 dark:text-gray-400' }} min-h-4 truncate text-xs leading-tight">
    @if($now)
        {{ $now->artist ? $now->artist.' – ' : '' }}{{ $now->title }}
    @else
        {{ $fallback }}
    @endif
</div>
