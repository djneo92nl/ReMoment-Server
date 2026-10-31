{{-- Poll only while the job is queued or running; a finished result then replaces the button --}}
<div @if(in_array($state, ['queued', 'running'], true)) wire:poll.3s @endif data-test="track-stems" class="text-gray-500 dark:text-gray-400">
    @if($stems)
        <x-stem-lanes :stems="$stems" :instrumental="$instrumental" :duration="$duration" :sections="$sections" :vocal-ratio="$vocalRatio" />
        @auth
            <button type="button" wire:click="remove" class="mt-1 text-[11px] text-gray-400 hover:underline">Remove</button>
        @endauth
    @elseif($state === 'queued')
        <span data-test="stems-queued"><i class="fa-solid fa-hourglass-half"></i> Waiting for the stems queue ({{ $estimate }} once it runs, an estimate)</span>
    @elseif($state === 'running')
        <span data-test="stems-running"><i class="fa-solid fa-spinner fa-spin"></i> Separating the stems, {{ $estimate }} (an estimate)</span>
    @elseif($canRequest)
        @if($state === 'failed')
            <span class="text-rose-500" data-test="stems-failed">Failed: {{ $message }}</span>
        @endif
        <button type="button" wire:click="analyse" class="rounded-md border border-gray-300 px-2 py-0.5 text-[11px] hover:bg-gray-100 dark:border-stone-600 dark:hover:bg-stone-800" data-test="stems-analyse">
            {{ $state === 'failed' ? 'Try again' : 'Analyse stems' }} (estimate: {{ $estimate }}, needs about {{ $ram }} GB RAM)
        </button>
    @elseif($hint)
        @if($state === 'failed')
            <span class="text-rose-500" data-test="stems-failed">Failed: {{ $message }}</span>
        @endif
        <span data-test="stems-hint">Stems are not available: set <code>STEMS_URL</code> and start the stems service (<code>docker compose --profile stems up -d</code>, see docs/architecture/stems-service.md).</span>
    @else
        <span>Not analysed</span>
    @endif
    @if($error)
        <span class="ml-2 text-rose-500">{{ $error }}</span>
    @endif
</div>
