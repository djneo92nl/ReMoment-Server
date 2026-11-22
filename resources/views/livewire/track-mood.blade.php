{{-- Poll only while the job is queued or running; a finished result then replaces the button --}}
<div @if(in_array($state, ['queued', 'running'], true)) wire:poll.3s @endif data-test="track-mood" class="text-gray-500 dark:text-gray-400">
    @if($curve)
        <x-mood-strip :curve="$curve" :duration="$duration" :height="22" class="rounded-xl overflow-hidden text-gray-600 dark:text-gray-300" />
        <p class="mt-1.5 text-[11px] text-gray-400 dark:text-gray-500" data-test="mood-summary">
            Top row: intensity (calm to intense). Bottom row: positivity. A model's estimate per {{ rtrim(rtrim(number_format($curve['window'], 1), '0'), '.') }} s, not a measurement.
            @if($summary) {{ $summary }} @endif
        </p>
        @auth
            <button type="button" wire:click="remove" class="mt-1 text-[11px] text-gray-400 hover:underline">Remove</button>
        @endauth
    @elseif($state === 'queued')
        <span data-test="mood-queued"><i class="fa-solid fa-hourglass-half"></i> Waiting for the analysis queue (about {{ $estimate }} s once it runs, an estimate)</span>
    @elseif($state === 'running')
        <span data-test="mood-running"><i class="fa-solid fa-spinner fa-spin"></i> Measuring the mood, about {{ $estimate }} s (an estimate)</span>
    @elseif($canRequest)
        @if($state === 'failed')
            <span class="text-rose-500" data-test="mood-failed">Failed: {{ $message }}</span>
        @endif
        <button type="button" wire:click="analyse" class="rounded-md border border-gray-300 px-2 py-0.5 text-[11px] hover:bg-gray-100 dark:border-stone-600 dark:hover:bg-stone-800" data-test="mood-analyse">
            {{ $state === 'failed' ? 'Try again' : 'Analyse mood' }} (estimate: about {{ $estimate }} s)
        </button>
    @else
        <span>Not analysed</span>
    @endif
    @if($error)
        <span class="ml-2 text-rose-500">{{ $error }}</span>
    @endif
</div>
