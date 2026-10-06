{{-- Dense cover grid: ~140px cells. Inline style: Tailwind CDN doesn't generate classes that first appear after a lazy load. --}}
<div {{ $attributes }} style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:1.25rem 1rem">
    {{ $slot }}
</div>
