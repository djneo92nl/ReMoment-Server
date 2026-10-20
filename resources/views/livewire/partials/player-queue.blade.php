{{-- Up next inside the player bar's panel. Click a track to play from there (queue_jump); remove, move and clear follow the queue_* capabilities. --}}
@php($canJump = in_array('queue_jump', $capabilities, true))
@php($canRemove = in_array('queue_remove', $capabilities, true))
@php($canMove = in_array('queue_move', $capabilities, true))
@php($canClear = in_array('queue_clear', $capabilities, true) && !empty($queue['items']))
@php($lastPosition = count($queue['items'] ?? []))
@php($rowButton = 'background:none;border:0;cursor:pointer;color:inherit;width:1.9rem;height:1.9rem;border-radius:9999px;display:flex;align-items:center;justify-content:center;flex:none;font-size:.75rem;opacity:.65')
<div style="padding:1rem .5rem 1rem 1rem;display:flex;flex-direction:column;min-height:0;overflow:hidden">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem;flex:none">
        <h3 style="font-size:1.1rem;font-weight:700">Up next</h3>
        <div style="display:flex;align-items:center;gap:.5rem">
            @if($canClear)
                <button type="button" wire:click="clearQueue" wire:confirm="Clear everything after the current track?" title="Clear the queue" style="background:none;border:0;cursor:pointer;color:inherit;font-size:.8rem;opacity:.7">Clear</button>
            @endif
            <x-modal-close class="!text-white/70" />
        </div>
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

    @if($queueError)
        <p style="font-size:.8rem;font-weight:600;margin-bottom:.4rem;flex:none">{{ $queueError }}</p>
    @endif

    @if($queue['error'])
        <p style="font-size:.85rem;opacity:.7">{{ $queue['error'] }}</p>
    @elseif(empty($queue['items']))
        <p style="font-size:.85rem;opacity:.7">Nothing queued after the current track.</p>
    @else
        <ol @if($canMove) x-data="queueSort" @dragstart="start($event)" @dragover="over($event)" @drop="drop($event)" @dragend="end()" @endif style="list-style:none;margin:0;padding:0 .5rem 0 0;overflow-y:auto;min-height:0;flex:1 1 auto">
            @foreach($queue['items'] as $i => $item)
                <li wire:key="q-{{ $i }}-{{ $item['uri'] ?? $item['name'] }}" @if($canMove) draggable="true" data-pos="{{ $i + 1 }}" data-uri="{{ $item['uri'] }}" @endif style="display:flex;align-items:center;gap:.15rem">
                    <button type="button" @if($canJump) wire:click="jumpToQueue({{ $i + 1 }})" title="Play from here" @else disabled @endif
                            style="display:flex;align-items:center;gap:.7rem;flex:1;min-width:0;padding:.4rem .5rem;border:0;border-radius:.6rem;background:none;color:inherit;text-align:left;{{ $canJump ? 'cursor:pointer' : 'cursor:default' }}"
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
                    @if($canMove && $i > 0)
                        <button type="button" wire:click="moveInQueue({{ $i + 1 }}, {{ $i }}, {{ \Illuminate\Support\Js::from($item['uri']) }})" title="Move up" style="{{ $rowButton }}"><i class="fa-solid fa-chevron-up"></i></button>
                    @endif
                    @if($canMove && $i < $lastPosition - 1)
                        <button type="button" wire:click="moveInQueue({{ $i + 1 }}, {{ $i + 2 }}, {{ \Illuminate\Support\Js::from($item['uri']) }})" title="Move down" style="{{ $rowButton }}"><i class="fa-solid fa-chevron-down"></i></button>
                    @endif
                    @if($canRemove)
                        <button type="button" wire:click="removeFromQueue({{ $i + 1 }}, {{ \Illuminate\Support\Js::from($item['uri']) }})" title="Remove from the queue" style="{{ $rowButton }}"><i class="fa-solid fa-xmark"></i></button>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
