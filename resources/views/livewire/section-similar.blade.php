{{-- Poll only while the job is queued or running; a finished result then replaces the button --}}
<div @if(in_array($state, ['queued', 'running'], true)) wire:poll.3s @endif data-test="section-similar" class="text-gray-500 dark:text-gray-400">
    @if($sections !== [])
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <x-select size="sm" wire:model.live="section" aria-label="Section to match" data-test="section-pick">
                @foreach($sections as $s)
                    <option value="{{ $s['index'] }}">Section {{ $s['index'] + 1 }}{{ $s['label'] ? ' ('.$s['label'].')' : '' }}, {{ \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $s['start']) }} – {{ \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $s['end']) }}</option>
                @endforeach
            </x-select>
            @auth
                <button type="button" wire:click="remove" class="text-[11px] text-gray-400 hover:underline">Remove</button>
            @endauth
        </div>
        @if($rows === [])
            <span class="text-xs" data-test="section-similar-empty">No analysed track has a section that sounds like it. Only tracks whose section sound was analysed are compared.</span>
        @else
            <div class="grid gap-x-4 gap-y-1 sm:grid-cols-2" data-test="section-similar-rows">
                @foreach($rows as $row)
                    <a href="{{ $row['track']->album_id ? route('albums.show', $row['track']->album_id) : '#' }}" data-test="section-match-{{ $row['track']->id }}"
                       class="flex items-center gap-3 rounded-2xl px-2 py-1.5 hover:bg-gray-100 dark:hover:bg-stone-900">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" title="{{ $row['track']->name }}">{{ $row['track']->name }}</p>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $row['track']->artist?->name }} · {{ \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $row['section']['start']) }} – {{ \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $row['section']['end']) }}</p>
                        </div>
                        <span class="text-[11px] tabular-nums text-gray-400 dark:text-gray-600" title="How alike the sound of the two sections is">{{ $row['similarity'] }}%</span>
                    </a>
                @endforeach
            </div>
        @endif
    @elseif(!$hasSections)
        <span data-test="section-similar-needs-sections">Analyse the sections first.</span>
    @elseif($state === 'queued')
        <span data-test="section-sound-queued"><i class="fa-solid fa-hourglass-half"></i> Waiting for the analysis queue (about {{ $estimate }} s once it runs, an estimate)</span>
    @elseif($state === 'running')
        <span data-test="section-sound-running"><i class="fa-solid fa-spinner fa-spin"></i> Listening to the sections, about {{ $estimate }} s (an estimate)</span>
    @elseif($canRequest)
        @if($state === 'failed')
            <span class="text-rose-500" data-test="section-sound-failed">Failed: {{ $message }}</span>
        @endif
        <button type="button" wire:click="analyse" class="rounded-md border border-gray-300 px-2 py-0.5 text-[11px] hover:bg-gray-100 dark:border-stone-600 dark:hover:bg-stone-800" data-test="section-sound-analyse">
            {{ $state === 'failed' ? 'Try again' : 'Analyse the sound of the sections' }} (estimate: about {{ $estimate }} s)
        </button>
    @else
        <span>Not analysed</span>
    @endif
    @if($error)
        <span class="ml-2 text-rose-500">{{ $error }}</span>
    @endif
</div>
