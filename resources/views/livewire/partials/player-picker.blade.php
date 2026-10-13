{{-- The player picker inside the player bar's panel (or floating bottom right when nothing is pinned). --}}
<div style="padding:1rem;display:flex;flex-direction:column;min-height:0;overflow:hidden">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.8rem;flex:none">
        <div>
            <h3 style="font-size:1.1rem;font-weight:700">Choose a player</h3>
            <p style="font-size:.8rem;opacity:.65">Play buttons in the library play on this device.</p>
        </div>
        <x-modal-close class="!text-white/70" />
    </div>
    <div style="display:flex;flex-direction:column;gap:.6rem;overflow-y:auto;min-height:0;flex:1 1 auto">
        {{-- Nothing: unpins the player; the bar goes away --}}
        <form method="POST" action="{{ route('player.clear') }}">
            @csrf
            @method('DELETE')
            <button type="submit" style="display:flex;align-items:center;gap:.75rem;width:100%;padding:.85rem 1rem;border-radius:1rem;border:0;cursor:pointer;color:inherit;text-align:left;background:rgba(255,255,255,{{ $device ? '.14' : '.22' }});{{ !$device ? 'box-shadow:0 0 0 2px #fff;' : '' }}">
                <span style="width:3rem;height:3rem;border-radius:.6rem;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;flex:none"><i class="fa-solid fa-ban" style="opacity:.7"></i></span>
                <span style="font-weight:600">No player</span>
            </button>
        </form>
        @foreach($players as $candidate)
            @php
                $session = \App\Domain\Device\Cache\MultiRoom::get($candidate->id)?->resolve($candidate);
                $tag = match ($session['role'] ?? null) {
                    'host' => $session['listeners'] ? 'Hosting +'.count($session['listeners']) : null,
                    'listener' => 'Joined '.($session['host']['name'] ?? ''),
                    default => null,
                };
            @endphp
            <x-mini-player :glass="true" :device="$candidate" :tag="$tag" tag-color="#fde68a" :volume-api="true"
                           :selected="($device?->id ?? null) === $candidate->id"
                           :select-url="route('player.select', $candidate)" wire:key="pick-{{ $candidate->id }}" />
        @endforeach
    </div>
</div>
