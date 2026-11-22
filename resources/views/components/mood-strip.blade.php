@props(['curve', 'duration' => null, 'height' => 12, 'seek' => false, 'only' => null])

{{-- The mood of a track over time (workstream 16.3) as neutral bars: `currentColor` at an opacity that follows the value,
     so calm is faint and intense is strong (no hue: DESIGN.md keeps colour for artwork). Two rows, intensity (arousal) on
     top and positivity (valence) below; `only` = 'arousal' draws just the first (the player bar). With `seek` a column is
     a button calling seekToSeconds() of the surrounding progressTicker. --}}
@php
    $window = max(0.1, (float) ($curve['window'] ?? 3));
    $rows = $only === 'arousal' ? ['arousal' => 'Intensity'] : ['arousal' => 'Intensity', 'valence' => 'Positivity'];
    $first = \App\Domain\Analysis\MoodCurve::columns($curve['arousal']);
    $count = count($first);
    $perColumn = $count > 0 ? count($curve['arousal']) / $count : 1; // curve points per column
    $total = (float) ($duration ?: count($curve['arousal']) * $window);
    $rowHeight = $only === 'arousal' ? $height : max(2, intdiv($height, 2));
@endphp
@if($count > 0)
    <div role="img" aria-label="Mood over time" data-test="mood-strip" {{ $attributes->merge(['style' => "width:100%"]) }}>
        @foreach($rows as $key => $label)
            @php $columns = \App\Domain\Analysis\MoodCurve::columns($curve[$key]); @endphp
            <div data-row="{{ $key }}" style="display:flex;width:100%;height:{{ $rowHeight }}px;{{ $loop->first ? '' : 'margin-top:1px' }}">
                @foreach($columns as $i => $value)
                    @php $at = $i * $perColumn * $window; @endphp
                    <span data-value="{{ $value }}" title="{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $at) }} · {{ strtolower($label) }} {{ $value }}"
                          @if($seek) @click.stop="seekToSeconds({{ round(min($at, $total), 1) }})" @endif
                          style="flex:1 1 0;min-width:0;background:currentColor;opacity:{{ \App\Domain\Analysis\MoodCurve::opacity($value) }};{{ $seek ? 'cursor:pointer;' : '' }}"></span>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
