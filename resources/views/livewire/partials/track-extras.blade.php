{{-- Add to library + lyrics for the playing track. Needs $canAdd, $addedUrl, $addFailure, $lyricsPlain, $lyricsSynced, $lyricsLanguage. --}}
<div class="mt-5 space-y-3">
    @if($canAdd)
        <button wire:click="addToLibrary" wire:loading.attr="disabled" wire:target="addToLibrary" type="button"
                class="flex items-center gap-2 px-4 py-2 rounded-2xl bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-700 dark:text-gray-300 text-sm font-medium transition-colors disabled:opacity-60">
            <i class="fa-solid fa-plus text-xs"></i>
            <span wire:loading.remove wire:target="addToLibrary">Add to library</span>
            <span style="display:none" wire:loading wire:target="addToLibrary">Adding…</span>
        </button>
    @elseif($addedUrl)
        <a href="{{ $addedUrl }}" class="inline-flex items-center gap-2 text-sm text-emerald-600 dark:text-emerald-400 hover:underline">
            <i class="fa-solid fa-check text-xs"></i>Added to the library — open
        </a>
    @endif
    @if($addFailure)
        <p class="text-sm text-red-500">{{ $addFailure }}</p>
    @endif

    @php($lyricsDisplay = $lyricsPlain ?? ($lyricsSynced ? preg_replace('/\[\d+:\d+\.\d+\]\s*/u', '', $lyricsSynced) : null))
    @if($lyricsDisplay)
        <div x-data="{ lyricsOpen: false }" class="border-t border-gray-200 dark:border-stone-700 pt-4">
            <button @click="lyricsOpen = !lyricsOpen" class="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">
                <i class="fa-solid fa-microphone text-xs"></i>
                <span x-text="lyricsOpen ? 'Hide lyrics' : 'Show lyrics'">Show lyrics</span>
                <i class="fa-solid fa-chevron-down text-xs transition-transform duration-200" :class="lyricsOpen ? 'rotate-180' : ''"></i>
            </button>
            <div x-show="lyricsOpen" x-transition x-cloak>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400" data-test="lyrics-tools">
                    @if($lyricsLanguage)
                        <x-badge variant="outline" data-test="lyrics-language">{{ \App\Domain\Lyrics\LyricsAnalysis::name($lyricsLanguage) }}</x-badge>
                    @endif
                    <button type="button" x-data="{ copied: false }" @click="navigator.clipboard.writeText($refs.lyricsText.innerText).then(() => { copied = true; setTimeout(() => copied = false, 1500) })" class="hover:text-gray-700 dark:hover:text-gray-200 transition-colors" data-test="lyrics-copy">
                        <i class="fa-solid fa-copy text-xs"></i> <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                    </button>
                    {{-- A link, not a feature: translation is the user's own tool; paste what Copy took. Nothing is sent from here. --}}
                    <a href="https://translate.google.com/?sl={{ $lyricsLanguage ?? 'auto' }}&tl=en&op=translate" target="_blank" rel="noopener" class="hover:text-gray-700 dark:hover:text-gray-200 transition-colors" data-test="lyrics-translate">
                        <i class="fa-solid fa-language text-xs"></i> Translate
                    </a>
                </div>
                <div class="mt-2 max-h-72 overflow-y-auto rounded-xl bg-gray-50 dark:bg-stone-800 p-4">
                    <pre x-ref="lyricsText" class="text-xs text-gray-600 dark:text-gray-400 whitespace-pre-wrap font-sans leading-relaxed">{{ $lyricsDisplay }}</pre>
                </div>
            </div>
        </div>
    @endif
</div>
