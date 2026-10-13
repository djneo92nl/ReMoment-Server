@props(['points', 'height' => 36])

{{-- A track's waveform as one path of vertical strokes; viewBox units are the points, so it scales to any width. --}}
@php
    $count = count($points);
    $mid = $height / 2;
    $d = '';
    foreach ($points as $i => $peak) {
        $half = max(0.5, $peak / 100 * $mid);
        $d .= sprintf('M%s %.1fV%.1f', $i + 0.5, $mid - $half, $mid + $half);
    }
@endphp
<svg viewBox="0 0 {{ $count }} {{ $height }}" preserveAspectRatio="none" role="img" aria-label="Waveform"
     {{ $attributes->merge(['style' => "width:100%;height:{$height}px;display:block"]) }}>
    <path d="{{ $d }}" stroke="currentColor" stroke-width="0.7" stroke-linecap="round" fill="none" opacity="0.6" />
</svg>
