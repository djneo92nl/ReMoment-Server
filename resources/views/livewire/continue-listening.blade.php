<div data-test="continue-listening">
    @if($rows !== [])
        <section>
            <h2 class="mb-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">Continue listening</h2>
            @if($notice)<p class="mb-2 text-xs text-amber-600 dark:text-amber-400" role="status">{{ $notice }}</p>@endif
            <div class="grid gap-x-4 gap-y-1 sm:grid-cols-2 xl:grid-cols-3" data-test="position-rows">
                @foreach($rows as $row)
                    @php
                        $left = $row['duration'] ? max(0, $row['duration'] - $row['position']) : null;
                        $clock = fn (int $s) => $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
                    @endphp
                    <div class="group flex items-center gap-3 rounded-2xl px-2 py-2 hover:bg-gray-100 dark:hover:bg-stone-900" data-test="{{ $row['kind'] }}-{{ $row['id'] }}">
                        <x-artwork-thumb :src="$row['artwork']['proxy_120'] ?? null" :colors="$row['artwork']['colors'] ?? null" :seed="$row['title']" icon="fa-solid fa-headphones" size="h-11 w-11" proxy="120" class="shrink-0" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" title="{{ $row['title'] }}">{{ $row['title'] }}</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $row['subtitle'] }}</p>
                            @if($row['percent'] !== null)
                                <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-gray-200 dark:bg-stone-800" role="progressbar" aria-valuenow="{{ $row['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                                    <div class="h-full rounded-full bg-gray-500 dark:bg-stone-400" style="width: {{ $row['percent'] }}%"></div>
                                </div>
                            @endif
                            <p class="mt-1 text-xs tabular-nums text-gray-400 dark:text-gray-600">{{ $clock($row['position']) }}@if($left !== null) · {{ $clock($left) }} left @endif</p>
                        </div>
                        <button type="button" wire:click="clear('{{ $row['kind'] }}', {{ $row['id'] }})" aria-label="Forget where you stopped in {{ $row['title'] }}" title="Forget this position"
                                class="flex h-9 w-9 items-center justify-center text-gray-400 transition hover:text-gray-700 dark:text-gray-500 dark:hover:text-gray-300 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100"><i class="fa-solid fa-xmark text-sm"></i></button>
                        <button type="button" wire:click="resume('{{ $row['kind'] }}', {{ $row['id'] }})" aria-label="Resume {{ $row['title'] }}" title="Resume on the pinned player"
                                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-900 transition hover:bg-gray-200 dark:text-gray-100 dark:hover:bg-stone-800"><i class="fa-solid fa-play text-sm"></i></button>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
