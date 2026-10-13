{{-- Up next inside the player bar's panel. Click a track to play from there (queue_jump); editing comes later. --}}
@php($canJump = in_array('queue_jump', $capabilities, true))
<div style="padding:1rem .5rem 1rem 1rem;display:flex;flex-direction:column;min-height:0;overflow:hidden">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;flex:none">
        <h3 style="font-size:1.1rem;font-weight:700">Up next</h3>
        <x-modal-close class="!text-white/70" />
    </div>
    {{-- What plays now, above what follows --}}
    @if($isActive)
        <div style="display:flex;align-items:center;gap:.7rem;padding:.4rem .5rem;margin-bottom:.4rem;border-radius:.6rem;background:rgba(255,255,255,.12);flex:none">
            <span style="width:1.2rem;display:flex;align-items:flex-end;justify-content:center;gap:2px;height:.9rem;flex:none" title="{{ $isPlaying ? 'Playing' : 'Paused' }}">
                @foreach([.6, 1, .75] as $n => $h)
                    <span style="width:3px;height:{{ $isPlaying ? '100' : $h * 100 }}%;background:{{ $accent }};border-radius:1px;transform-origin:bottom;{{ $isPlaying ? 'animation:eq-bar .9s ease-in-out '.($n * .2).'s infinite alternate;' : '' }}"></span>
                @endforeach
            </span>
            <span style="width:2.5rem;height:2.5rem;border-radius:.4rem;overflow:hidden;flex:none;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center">
                @if($thumb)<img src="{{ $thumb }}" alt="" style="width:100%;height:100%;object-fit:cover">@else<i class="fa-solid fa-music" style="opacity:.5;font-size:.8rem"></i>@endif
            </span>
            <span style="min-width:0;flex:1">
                <span class="truncate" style="display:block;font-size:.88rem;font-weight:600;color:{{ $accent }}">{{ $title }}</span>
                @if($line2)<span class="truncate" style="display:block;font-size:.75rem;opacity:.6">{{ $line2 }}</span>@endif
            </span>
        </div>
        <style>@keyframes eq-bar { from { transform: scaleY(.3) } to { transform: scaleY(1) } } </style>
    @endif

    @if($queue['error'])
        <p style="font-size:.85rem;opacity:.7">{{ $queue['error'] }}</p>
    @elseif(empty($queue['items']))
        <p style="font-size:.85rem;opacity:.7">Nothing queued after the current track.</p>
    @else
        <ol style="list-style:none;margin:0;padding:0 .5rem 0 0;overflow-y:auto;min-height:0;flex:1 1 auto">
            @foreach($queue['items'] as $i => $item)
                <li wire:key="q-{{ $i }}-{{ $item['uri'] ?? $item['name'] }}">
                    <button type="button" @if($canJump) wire:click="jumpToQueue({{ $i + 1 }})" title="Play from here" @else disabled @endif
                            style="display:flex;align-items:center;gap:.7rem;width:100%;padding:.4rem .5rem;border:0;border-radius:.6rem;background:none;color:inherit;text-align:left;{{ $canJump ? 'cursor:pointer' : 'cursor:default' }}"
                            onmouseover="this.style.background='rgba(255,255,255,.12)'" onmouseout="this.style.background='none'">
                        <span style="width:1.2rem;text-align:right;font-size:.72rem;opacity:.5;flex:none">{{ $i + 1 }}</span>
                        <span style="width:2.5rem;height:2.5rem;border-radius:.4rem;overflow:hidden;flex:none;background:rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center">
                            @if($item['image'])
                                <img src="{{ $item['image'] }}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover">
                            @else
                                <i class="fa-solid fa-music" style="opacity:.5;font-size:.8rem"></i>
                            @endif
                        </span>
                        <span style="min-width:0;flex:1">
                            <span class="truncate" style="display:block;font-size:.88rem;font-weight:600">{{ $item['name'] }}</span>
                            @if($item['artist'] || $item['album'])
                                <span class="truncate" style="display:block;font-size:.75rem;opacity:.6">{{ collect([$item['artist'], $item['album']])->filter()->implode(' · ') }}</span>
                            @endif
                        </span>
                        @if($item['duration'])
                            <span style="font-size:.72rem;opacity:.55;flex:none;font-variant-numeric:tabular-nums">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes($item['duration']) }}</span>
                        @endif
                    </button>
                </li>
            @endforeach
        </ol>
    @endif
</div>
