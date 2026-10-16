@props(['title', 'href' => null])
{{-- A library row: a bold heading (a link with a › when `href`), then a sideways scroller of fixed-width cards that bleeds to the page edges.
     Children set their own width (`w-36 shrink-0 snap-start`); `x-album-card`, `x-artist-card`, `x-media-card` and `x-genre-tile` do.
     Mouse users get prev/next buttons (md+); the scroller is focusable so the arrow keys scroll it. --}}
<section x-data="{ by(dir) { const row = $refs.row; row.scrollBy({ left: dir * row.clientWidth * 0.85, behavior: 'smooth' }) } }" {{ $attributes }}>
    <div class="mb-3 flex items-center justify-between gap-4">
        <h2 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">
            @if($href)
                <a href="{{ $href }}" class="inline-flex items-center gap-1.5 hover:underline">{{ $title }}<i class="fa-solid fa-chevron-right text-xs text-gray-400 dark:text-gray-600"></i></a>
            @else
                {{ $title }}
            @endif
        </h2>
        <div class="hidden gap-1.5 md:flex">
            <button type="button" @click="by(-1)" aria-label="Scroll {{ $title }} back" class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-500 transition hover:bg-gray-200 dark:bg-stone-800 dark:text-gray-400 dark:hover:bg-stone-700"><i class="fa-solid fa-chevron-left text-xs"></i></button>
            <button type="button" @click="by(1)" aria-label="Scroll {{ $title }} forward" class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-500 transition hover:bg-gray-200 dark:bg-stone-800 dark:text-gray-400 dark:hover:bg-stone-700"><i class="fa-solid fa-chevron-right text-xs"></i></button>
        </div>
    </div>
    <div x-ref="row" tabindex="0" role="region" aria-label="{{ $title }}"
         class="no-scrollbar -mx-5 sm:-mx-8 lg:-mx-12 flex gap-4 overflow-x-auto snap-x snap-mandatory scroll-px-5 sm:scroll-px-8 lg:scroll-px-12 px-5 sm:px-8 lg:px-12 pb-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-400 dark:focus-visible:ring-stone-500 rounded-2xl">
        {{ $slot }}
    </div>
</section>
