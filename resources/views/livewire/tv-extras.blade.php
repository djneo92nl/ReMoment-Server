<div>
@if($capabilities)
    <x-card class="p-8" title="Also">
        @if($error)
            <p class="mb-4 text-xs text-red-500">{{ $error }}</p>
        @endif

        @if($sleepTimer !== null)
            <div class="flex items-center justify-between gap-3 mb-5">
                <span class="text-sm text-gray-700 dark:text-gray-300">Sleep timer</span>
                @if($sleepTimer['writable'])
                    <div class="flex gap-2">
                        @foreach([0 => 'Off', 15 => '15', 30 => '30', 60 => '60'] as $minutes => $label)
                            <button type="button" wire:click="setSleepTimer({{ $minutes }})"
                                    class="px-3 py-1.5 rounded-xl text-sm bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 {{ $sleepTimer['value'] === $minutes ? 'ring-2 ring-gray-900 dark:ring-gray-100 font-semibold' : 'text-gray-600 dark:text-gray-400' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                @else
                    <span class="text-sm text-gray-400 dark:text-gray-500">{{ $sleepTimer['value'] === 0 ? 'Off' : $sleepTimer['value'].' min' }}</span>
                @endif
            </div>
        @endif

        @if(in_array('digits', $capabilities, true))
            <p class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-2">Number keys</p>
            <div class="grid grid-cols-3 gap-2 max-w-xs">
                @foreach([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                    <button type="button" wire:click="pressDigit({{ $digit }})" wire:loading.attr="disabled" wire:target="pressDigit"
                            class="py-3 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-800 dark:text-gray-200 rounded-xl text-lg font-medium disabled:opacity-50">{{ $digit }}</button>
                @endforeach
                <button type="button" wire:click="pressDigit(0)" wire:loading.attr="disabled" wire:target="pressDigit"
                        class="col-start-2 py-3 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-800 dark:text-gray-200 rounded-xl text-lg font-medium disabled:opacity-50">0</button>
            </div>
        @endif
    </x-card>
@endif
</div>
