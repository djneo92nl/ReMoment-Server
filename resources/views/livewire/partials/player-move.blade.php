{{-- "Move playback to…" inside the player bar's panel: pick the device the pinned player's playback continues on. --}}
<div style="padding:1rem;display:flex;flex-direction:column;min-height:0;overflow:hidden">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.8rem;flex:none">
        <div>
            <h3 style="font-size:1.1rem;font-weight:700">Move playback to…</h3>
            <p style="font-size:.8rem;opacity:.65">Continues on the device you pick, from the same place.</p>
        </div>
        <x-modal-close class="!text-white/70" />
    </div>

    @if($moveError)
        <p class="text-red-300" style="font-size:.8rem;margin-bottom:.75rem">{{ $moveError }}</p>
    @endif

    @if($moved)
        <div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:.75rem;padding:.7rem .9rem;border-radius:.8rem;background:rgba(255,255,255,.14);font-size:.85rem">
            <span>Moved to <strong>{{ $moved['name'] }}</strong>@if($moved['skipped'] > 0) · {{ $moved['skipped'] }} track(s) it can't play left out @endif</span>
            <form method="POST" action="{{ route('player.select', $moved['id']) }}">
                @csrf
                <button type="submit" style="background:none;border:0;cursor:pointer;color:inherit;text-decoration:underline;font-size:.8rem">Control it here</button>
            </form>
        </div>
    @endif

    <div style="display:flex;flex-direction:column;gap:.6rem;overflow-y:auto;min-height:0;flex:1 1 auto">
        @forelse($moveTargets as $target)
            <div wire:click="movePlayback({{ $target->id }})" wire:key="move-{{ $target->id }}" style="cursor:pointer">
                <x-mini-player :glass="true" :device="$target" />
            </div>
        @empty
            <p style="font-size:.85rem;opacity:.7">There is no other device to move to.</p>
        @endforelse
    </div>
</div>
