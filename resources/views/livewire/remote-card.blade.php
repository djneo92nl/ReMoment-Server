@php
    $btn = 'w-full h-12 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-800 dark:text-gray-200 rounded-xl text-sm font-medium focus-visible:ring-2 focus-visible:ring-gray-900 dark:focus-visible:ring-gray-100 disabled:opacity-50';
    $labels = [
        'up' => '▲', 'down' => '▼', 'left' => '◀', 'right' => '▶', 'ok' => 'OK',
        'home' => 'Home', 'back' => 'Back', 'menu' => 'Options', 'source' => 'Source', 'info' => 'Info',
        'volume_down' => 'Vol −', 'volume_up' => 'Vol +', 'mute' => 'Mute', 'channel_down' => 'Ch −', 'channel_up' => 'Ch +',
        'rewind' => '⏪', 'play' => '▶ Play', 'fast_forward' => '⏩', 'previous' => '⏮', 'pause' => 'Pause', 'next' => '⏭', 'stop' => 'Stop',
        'red' => 'Red', 'green' => 'Green', 'yellow' => 'Yellow', 'blue' => 'Blue',
    ];
    // Each block is a 3-column grid; null keeps an empty cell so keys stay where a remote has them.
    $blocks = [
        [null, 'up', null, 'left', 'ok', 'right', null, 'down', null],
        ['home', 'back', 'menu', 'source', 'info'],
        ['volume_down', 'mute', 'volume_up', 'channel_down', null, 'channel_up'],
        ['rewind', 'play', 'fast_forward', 'previous', 'pause', 'next', null, 'stop'],
        ['red', 'green', 'yellow', 'blue'],
    ];
@endphp
<div>
@if($keys || $digits)
    <x-card class="p-8" title="Remote">
        @if($error)
            <p class="mb-4 text-xs text-red-500">{{ $error }}</p>
        @endif

        <div class="space-y-6 max-w-xs">
            @foreach($blocks as $block)
                @if(array_intersect($block, $keys))
                    <div class="grid {{ count($block) === 4 ? 'grid-cols-2' : 'grid-cols-3' }} gap-2">
                        @foreach($block as $key)
                            @if($key !== null && in_array($key, $keys, true))
                                <button type="button" wire:click="press('{{ $key }}')" wire:loading.attr="disabled" wire:target="press"
                                        aria-label="{{ str_replace('_', ' ', $key) }}" class="{{ $btn }}">{{ $labels[$key] }}</button>
                            @else
                                <div></div>
                            @endif
                        @endforeach
                    </div>
                @endif
            @endforeach

            @if($digits)
                <div class="grid grid-cols-3 gap-2">
                    @foreach([1, 2, 3, 4, 5, 6, 7, 8, 9, null, 0, null] as $digit)
                        @if($digit !== null)
                            <button type="button" wire:click="pressDigit({{ $digit }})" wire:loading.attr="disabled" wire:target="pressDigit"
                                    class="{{ $btn }} text-lg">{{ $digit }}</button>
                        @else
                            <div></div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </x-card>
@endif
</div>
