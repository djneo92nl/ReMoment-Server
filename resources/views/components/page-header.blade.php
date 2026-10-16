@props(['title', 'subtitle' => null, 'back' => null])
{{-- Goes in <x-slot name="header">. `actions` slot = buttons on the right (wraps below the title on narrow screens); `badge` slot = next to the title (an icon, a tag); `meta` slot = the subtitle line when it holds links; the default slot sits under the subtitle (tabs, meta). --}}
<div class="flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-4 min-w-0">
        @if($back)
            <x-back-button :href="$back" />
        @endif
        <div class="min-w-0">
            <div class="flex items-center gap-3">
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight text-gray-900 dark:text-gray-100">{{ $title }}</h1>
                @isset($badge)
                    {{ $badge }}
                @endisset
            </div>
            @if($subtitle)
                <p class="mt-1.5 text-gray-500">{{ $subtitle }}</p>
            @elseif(isset($meta))
                <p class="mt-1.5 text-gray-500">{{ $meta }}</p>
            @endif
            @if(! $slot->isEmpty())
                <div class="mt-3">{{ $slot }}</div>
            @endif
        </div>
    </div>
    @isset($actions)
        <div class="flex items-center gap-2 flex-shrink-0">{{ $actions }}</div>
    @endisset
</div>
