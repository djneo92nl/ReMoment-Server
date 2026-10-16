@props(['title', 'href' => null, 'link' => null])
{{-- Small uppercase label above a block of the page, with an optional "more" link on the right. --}}
<div {{ $attributes->merge(['class' => 'flex items-center justify-between mb-4']) }}>
    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">{{ $title }}</h2>
    @if($href)
        <a href="{{ $href }}" class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 dark:hover:text-gray-400 transition-colors">{{ $link }}</a>
    @endif
</div>
