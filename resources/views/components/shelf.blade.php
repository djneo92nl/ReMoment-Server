@props(['title', 'href' => null])
{{-- A library row: a bold heading (a link with a › when `href`), then a sideways scroller of fixed-width cards that bleeds to the page edges.
     Children set their own width (`w-40 shrink-0 snap-start`); `x-album-card`, `x-artist-card`, `x-media-card` and `x-genre-tile` do. --}}
<section {{ $attributes }}>
    <h2 class="mb-3 text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">
        @if($href)
            <a href="{{ $href }}" class="inline-flex items-center gap-1.5 hover:underline">{{ $title }}<i class="fa-solid fa-chevron-right text-xs text-gray-400 dark:text-gray-600"></i></a>
        @else
            {{ $title }}
        @endif
    </h2>
    <div class="no-scrollbar -mx-5 sm:-mx-8 lg:-mx-12 flex gap-4 overflow-x-auto snap-x snap-mandatory scroll-px-5 sm:scroll-px-8 lg:scroll-px-12 px-5 sm:px-8 lg:px-12 pb-2">
        {{ $slot }}
    </div>
</section>
