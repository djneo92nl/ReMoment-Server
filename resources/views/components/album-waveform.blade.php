@props(['tracks', 'height' => 72])
{{-- One silhouette for the whole album: every track's peaks side by side, each track as wide as it is long, a thin gap between tracks, in the surrounding ink (currentColor).
     A track without a waveform is a dotted line, so the strip always reads as the album's length. Each segment has an invisible link to its row (#track-ID, the title in a tooltip).
     The strip has a fixed height and an `overlay` layer above it, so a progress marker can be added later without a re-layout. --}}
@php
    $width = 1000;
    $gap = 3;
    $weights = $tracks->map(fn ($t) => max(1, (int) ($t->duration ?: 180)));
    $usable = $width - $gap * max(0, $tracks->count() - 1);
    $mid = $height / 2;
    $x = 0.0;
    $segments = [];
    foreach ($tracks as $i => $track) {
        $w = $usable * $weights[$i] / $weights->sum();
        $points = $track->waveform();
        $d = null;
        $step = null;
        if ($points) {
            $step = $w / count($points);
            $d = '';
            foreach ($points as $k => $peak) {
                $half = max(1, $peak / 100 * ($mid - 2));
                $d .= sprintf('M%.2f %.1fV%.1f', $x + ($k + 0.5) * $step, $mid - $half, $mid + $half);
            }
        }
        $segments[] = ['track' => $track, 'x' => $x, 'w' => $w, 'd' => $d, 'step' => $step, 'weight' => $weights[$i]];
        $x += $w + $gap;
    }
@endphp
<div {{ $attributes->merge(['class' => 'relative']) }} style="height:{{ $height }}px">
    <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" role="img" aria-label="Waveform of the album" class="absolute inset-0 h-full w-full">
        @foreach($segments as $seg)
            @if($seg['d'] !== null)
                <path d="{{ $seg['d'] }}" stroke="currentColor" stroke-width="{{ round(max(0.4, $seg['step'] * 0.62), 2) }}" fill="none" opacity="0.6" />
            @else
                <line x1="{{ round($seg['x'], 2) }}" x2="{{ round($seg['x'] + $seg['w'], 2) }}" y1="{{ $mid }}" y2="{{ $mid }}" stroke="currentColor" stroke-width="2" stroke-dasharray="1 5" stroke-linecap="round" vector-effect="non-scaling-stroke" opacity="0.4" />
            @endif
        @endforeach
    </svg>
    <div data-strip-links class="absolute inset-0 flex" style="gap:{{ $gap / $width * 100 }}%">
        @foreach($segments as $seg)
            <a href="#track-{{ $seg['track']->id }}" style="flex:{{ $seg['weight'] }} 1 0;min-width:4px" class="block rounded-sm outline-offset-2 hover:bg-black/10 focus-visible:bg-black/10 dark:hover:bg-white/10 dark:focus-visible:bg-white/10"
               title="{{ $seg['track']->name }}{{ $seg['track']->duration ? ' · '.\App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $seg['track']->duration) : '' }}"
               aria-label="{{ $seg['track']->name }}"></a>
        @endforeach
    </div>
    <div data-strip-overlay class="pointer-events-none absolute inset-0"></div>
</div>
