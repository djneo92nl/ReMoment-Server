@props(['sections', 'duration' => null, 'height' => 6, 'labels' => false, 'seek' => false])

{{-- The sections of a track as neutral blocks along its length (currentColor at different opacities: the same repetition
     letter shares a shade, never "verse"/"chorus"). A letter is printed only when `confidence` supports it. With `seek`
     a block is a button calling seekToSeconds(start) of the surrounding progressTicker. --}}
@php
    $total = (float) ($duration ?: (collect($sections)->max('end') ?? 0));
    $shades = [0.55, 0.32, 0.45, 0.24, 0.62, 0.38];
@endphp
@if($total > 0 && count($sections) > 0)
    <div role="img" aria-label="Sections of the track" data-test="section-strip" {{ $attributes->merge(['style' => "display:flex;gap:1px;width:100%;height:{$height}px"]) }}>
        @foreach($sections as $s)
            @php
                $letter = $s['label'] ?? null;
                $sure = $letter !== null && (float) ($s['confidence'] ?? 0) >= 0.5;
                $opacity = $letter === null ? 0.14 : $shades[(ord($letter) - 65) % count($shades)];
                $grow = max(0.001, ((float) $s['end'] - (float) $s['start']) / $total);
                $title = \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $s['start']).' – '.\App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $s['end']).($sure ? ' · '.$letter : '');
            @endphp
            <div title="{{ $title }}" data-start="{{ $s['start'] }}"
                 @if($seek) @click.stop="seekToSeconds({{ (float) $s['start'] }})" @endif
                 style="flex:{{ $grow }} 1 0;min-width:1px;position:relative;{{ $seek ? 'cursor:pointer;' : '' }}">
                <span style="position:absolute;inset:0;background:currentColor;opacity:{{ $opacity }};border-radius:1px"></span>
                @if($labels && $sure)
                    <span style="position:relative;display:block;text-align:center;font-size:.65rem;line-height:{{ $height }}px;font-weight:600;overflow:hidden">{{ $letter }}</span>
                @endif
            </div>
        @endforeach
    </div>
@endif
