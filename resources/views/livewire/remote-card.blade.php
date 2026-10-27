@php
    $has = fn (string $key) => in_array($key, $keys, true);
    $btn = 'py-3 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-800 dark:text-gray-200 rounded-xl text-sm font-medium focus-visible:ring-2 focus-visible:ring-gray-900 dark:focus-visible:ring-gray-100 disabled:opacity-50';
    $labels = [
        'home' => 'Home', 'back' => 'Back', 'menu' => 'Options', 'source' => 'Source', 'info' => 'Info',
        'play' => 'Play', 'pause' => 'Pause', 'stop' => 'Stop', 'rewind' => 'Rew', 'fast_forward' => 'FF', 'previous' => 'Prev', 'next' => 'Next',
    ];
    $dpad = ['up' => 'col-start-2 row-start-1', 'left' => 'col-start-1 row-start-2', 'ok' => 'col-start-2 row-start-2', 'right' => 'col-start-3 row-start-2', 'down' => 'col-start-2 row-start-3'];
    $dpadLabels = ['up' => '▲', 'down' => '▼', 'left' => '◀', 'right' => '▶', 'ok' => 'OK'];
    $colours = ['red' => 'Red', 'green' => 'Green', 'yellow' => 'Yellow', 'blue' => 'Blue'];
@endphp
<div>
@if($keys)
    <x-card class="p-8" title="Remote">
        @if($error)
            <p class="mb-4 text-xs text-red-500">{{ $error }}</p>
        @endif

        <div class="space-y-6">
            @if(array_intersect(array_keys($dpad), $keys))
                <div class="grid grid-cols-3 grid-rows-3 gap-2 max-w-xs">
                    @foreach($dpad as $key => $position)
                        @if($has($key))
                            <button type="button" wire:click="press('{{ $key }}')" wire:loading.attr="disabled" wire:target="press"
                                    aria-label="{{ $key }}" class="{{ $position }} {{ $btn }}">{{ $dpadLabels[$key] }}</button>
                        @endif
                    @endforeach
                </div>
            @endif

            @if(array_intersect(['home', 'back', 'menu', 'source', 'info'], $keys))
                <div class="flex flex-wrap gap-2">
                    @foreach(['home', 'back', 'menu', 'source', 'info'] as $key)
                        @if($has($key))
                            <button type="button" wire:click="press('{{ $key }}')" wire:loading.attr="disabled" wire:target="press" class="px-4 {{ $btn }}">{{ $labels[$key] }}</button>
                        @endif
                    @endforeach
                </div>
            @endif

            @if(array_intersect(['volume_up', 'volume_down', 'mute', 'channel_up', 'channel_down'], $keys))
                <div class="flex flex-wrap gap-2">
                    @foreach(['volume_down' => 'Vol −', 'volume_up' => 'Vol +', 'mute' => 'Mute', 'channel_down' => 'Ch −', 'channel_up' => 'Ch +'] as $key => $label)
                        @if($has($key))
                            <button type="button" wire:click="press('{{ $key }}')" wire:loading.attr="disabled" wire:target="press" class="px-4 {{ $btn }}">{{ $label }}</button>
                        @endif
                    @endforeach
                </div>
            @endif

            @if(array_intersect(['rewind', 'previous', 'play', 'pause', 'stop', 'next', 'fast_forward'], $keys))
                <div class="flex flex-wrap gap-2">
                    @foreach(['rewind', 'previous', 'play', 'pause', 'stop', 'next', 'fast_forward'] as $key)
                        @if($has($key))
                            <button type="button" wire:click="press('{{ $key }}')" wire:loading.attr="disabled" wire:target="press" class="px-4 {{ $btn }}">{{ $labels[$key] }}</button>
                        @endif
                    @endforeach
                </div>
            @endif

            @if(array_intersect(array_keys($colours), $keys))
                <div class="flex flex-wrap gap-2">
                    @foreach($colours as $key => $label)
                        @if($has($key))
                            <button type="button" wire:click="press('{{ $key }}')" wire:loading.attr="disabled" wire:target="press" class="px-4 {{ $btn }}">{{ $label }}</button>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </x-card>
@endif
</div>
