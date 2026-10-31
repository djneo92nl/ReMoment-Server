@props(['stems', 'instrumental' => [], 'duration' => null, 'sections' => null, 'vocalRatio' => null, 'height' => 28])

{{-- One lane per stem (vocals, drums, bass, other) from the stored loudness curves, each on its own scale
     (App\Domain\Analysis\StemLanes), so a quiet stem is as readable as a loud one. The sections strip, when sections
     exist, sits on top in the same column so boundaries line up in time; instrumental ranges are a tint across every lane. --}}
@php
    $lanes = ['vocals' => 'Vocals', 'drums' => 'Drums', 'bass' => 'Bass', 'other' => 'Other'];
    $rate = max(1, (int) ($stems['rate_hz'] ?? 2));
    $span = max(1, count($stems['vocals'] ?? [])) / $rate;   // seconds the curves cover
    $ranges = collect($instrumental)->map(fn ($r) => [
        'left' => round(max(0, min(100, (float) $r['start'] / $span * 100)), 2),
        'width' => round(max(0, min(100, ((float) $r['end'] - (float) $r['start']) / $span * 100)), 2),
        'label' => \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $r['start']).' – '.\App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $r['end']),
    ]);
@endphp
<div data-test="stem-lanes" role="group" aria-label="Stems of the track" class="text-gray-600 dark:text-gray-300">
    <div style="display:grid;grid-template-columns:3.25rem minmax(0,1fr);gap:3px 8px;align-items:center">
        @if($sections)
            <span class="text-[11px] text-gray-400 dark:text-gray-600">Sections</span>
            <x-section-strip :sections="$sections" :duration="$duration ?: $span" :height="10" />
        @endif
        @foreach($lanes as $key => $label)
            @php
                $heights = \App\Domain\Analysis\StemLanes::heights($stems[$key] ?? []);
                $columns = count($heights);
            @endphp
            <span class="text-[11px] text-gray-500 dark:text-gray-400">{{ $label }}</span>
            <div style="position:relative;height:{{ $height }}px" class="rounded-xl bg-gray-100 dark:bg-stone-800/60 overflow-hidden" data-lane="{{ $key }}">
                @if($columns > 0)
                    <svg viewBox="0 0 {{ $columns }} 100" preserveAspectRatio="none" role="img" aria-label="{{ $label }} loudness over time" style="position:absolute;inset:0;width:100%;height:100%;display:block">
                        <path d="{{ \App\Domain\Analysis\StemLanes::path($heights) }}" fill="currentColor" opacity="0.6" />
                    </svg>
                @endif
                @foreach($ranges as $r)
                    <span title="Instrumental {{ $r['label'] }}" data-instrumental style="position:absolute;top:0;bottom:0;left:{{ $r['left'] }}%;width:{{ $r['width'] }}%;background:currentColor;opacity:.2;box-shadow:inset 1px 0 currentColor,inset -1px 0 currentColor"></span>
                @endforeach
            </div>
        @endforeach
    </div>
    <p class="mt-1.5 text-[11px] text-gray-400 dark:text-gray-500" data-test="stem-summary">
        @if($vocalRatio !== null)
            Vocals in {{ \App\Domain\Analysis\StemLanes::percent((float) $vocalRatio) }} of the track.
        @endif
        @if($ranges->isNotEmpty())
            Instrumental (shaded): {{ $ranges->pluck('label')->implode(', ') }}.
        @else
            No instrumental section found.
        @endif
        Each lane is drawn on its own scale.
    </p>
</div>
