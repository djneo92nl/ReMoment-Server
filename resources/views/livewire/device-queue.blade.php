<div x-data="liveDevice({{ $device->id }}, 10000, 60000)" wire:init="load"
     class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-8">
    <h2 class="text-base font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-5">Up Next</h2>

    @if(!$ready)
        <div class="space-y-3">
            @for($i = 0; $i < 3; $i++)
                <div class="h-10 bg-gray-100 dark:bg-stone-800 rounded-xl animate-pulse"></div>
            @endfor
        </div>
    @elseif($error)
        <p class="text-sm text-gray-500 dark:text-gray-500">{{ $error }}</p>
    @elseif(empty($items))
        <p class="text-sm text-gray-500 dark:text-gray-500">Nothing queued after the current track.</p>
    @else
        <ol class="space-y-1">
            @foreach($items as $i => $item)
                <li class="flex items-center gap-3 px-2 py-2 rounded-xl hover:bg-gray-50 dark:hover:bg-stone-800/50 transition-colors">
                    <span class="w-5 text-xs text-right text-gray-400 dark:text-gray-600 flex-shrink-0">{{ $i + 1 }}</span>
                    @if($item['image'])
                        <img src="{{ $item['image'] }}" alt="" loading="lazy" class="w-9 h-9 rounded-lg object-cover flex-shrink-0">
                    @else
                        <div class="w-9 h-9 rounded-lg bg-gray-100 dark:bg-stone-800 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-music text-xs text-gray-300 dark:text-stone-600"></i>
                        </div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ $item['name'] }}</div>
                        @if($item['artist'] || $item['album'])
                            <div class="text-xs text-gray-500 dark:text-gray-500 truncate">
                                {{ collect([$item['artist'], $item['album']])->filter()->implode(' · ') }}
                            </div>
                        @endif
                    </div>
                    @if($item['duration'])
                        <span class="text-xs text-gray-400 dark:text-gray-600 flex-shrink-0">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($item['duration']) }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
