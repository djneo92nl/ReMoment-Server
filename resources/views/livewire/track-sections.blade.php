{{-- Poll only while the job is queued or running; a finished result then replaces the button --}}
<div @if(in_array($state, ['queued', 'running'], true)) wire:poll.3s @endif data-test="track-sections" class="text-gray-500 dark:text-gray-400">
    @if($sections)
        <x-section-strip :sections="$sections" :duration="$duration" :height="18" :labels="true" class="text-gray-600 dark:text-gray-300" style="display:flex;gap:1px;width:100%;height:18px" />
        @auth
            <button type="button" wire:click="remove" class="mt-1 text-[11px] text-gray-400 hover:underline">Remove</button>
        @endauth
    @elseif(is_array($sections))
        <span data-test="sections-empty">{{ $reason === 'too_short' ? 'Too short to find sections' : 'No structure found' }}</span>
        @auth
            <button type="button" wire:click="remove" class="ml-2 text-[11px] text-gray-400 hover:underline">Analyse again</button>
        @endauth
    @elseif($state === 'queued')
        <span data-test="sections-queued"><i class="fa-solid fa-hourglass-half"></i> Waiting for the analysis queue (about {{ $estimate }} s once it runs, an estimate)</span>
    @elseif($state === 'running')
        <span data-test="sections-running"><i class="fa-solid fa-spinner fa-spin"></i> Analysing sections, about {{ $estimate }} s (an estimate)</span>
    @elseif($canRequest)
        @if($state === 'failed')
            <span class="text-rose-500" data-test="sections-failed">Failed: {{ $message }}</span>
        @endif
        <button type="button" wire:click="analyse" class="rounded-md border border-gray-300 px-2 py-0.5 text-[11px] hover:bg-gray-100 dark:border-stone-600 dark:hover:bg-stone-800" data-test="sections-analyse">
            {{ $state === 'failed' ? 'Try again' : 'Analyse sections' }} (estimate: about {{ $estimate }} s)
        </button>
    @else
        <span>Not analysed</span>
    @endif
    @if($error)
        <span class="ml-2 text-rose-500">{{ $error }}</span>
    @endif
</div>
