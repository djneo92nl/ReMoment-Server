{{-- Dense cover grid: ~140px cells (the minmax track has no Tailwind utility, so it stays an inline style). --}}
<div {{ $attributes }} style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:1.25rem 1rem">
    {{ $slot }}
</div>
