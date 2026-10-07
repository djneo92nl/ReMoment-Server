<div style="display:flex;align-items:center;gap:.75rem">
    <div class="bg-gray-200 dark:bg-stone-700" style="width:3rem;height:3rem;border-radius:.6rem;overflow:hidden;flex:none;display:flex;align-items:center;justify-content:center">
        @if($thumb)
            <img src="{{ $thumb }}" alt="" style="width:100%;height:100%;object-fit:cover">
        @else
            <i class="fa-solid {{ $radio ? 'fa-tower-broadcast' : 'fa-music' }} text-gray-400 dark:text-stone-500"></i>
        @endif
    </div>
    <div style="min-width:0;flex:1">
        <div style="display:flex;align-items:center;gap:.5rem">
            <p class="truncate" style="font-weight:600;font-size:1rem;line-height:1.3">{{ $device->device_name }}</p>
            <x-battery :device-id="$device->id" />
            @if($tag)
                <span style="font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;flex:none;color:{{ $tagColor }}"><i class="fa-solid fa-layer-group" style="font-size:.6rem"></i> {{ $tag }}</span>
            @endif
        </div>
        <p class="truncate text-gray-600 dark:text-gray-300" style="font-size:.85rem">{{ $title }}</p>
        @if($subtitle)
            <p class="truncate text-gray-400 dark:text-gray-500" style="font-size:.75rem">{{ $subtitle }}</p>
        @endif
    </div>
    @if($inSession !== null)
        @php
            $circle = 'width:1.9rem;height:1.9rem;flex:none;border-radius:9999px;display:flex;align-items:center;justify-content:center;padding:0;';
        @endphp
        @if($toggle)
            <button type="button" wire:click="{{ $toggle }}" wire:loading.attr="disabled" @if($closeOnToggle) @click="$dispatch('close')" @endif
                    title="{{ $inSession ? 'Remove from this session' : 'Add to this session' }}"
                    class="{{ $inSession ? '' : 'border border-gray-300 dark:border-stone-600' }}"
                    style="{{ $circle }}cursor:pointer;{{ $inSession ? 'background:#fbbf24;border:0;' : 'background:none;' }}">
                @if($inSession)<i class="fa-solid fa-check" style="font-size:.8rem;color:#1f2937"></i>@endif
            </button>
        @else
            <span style="{{ $circle }}background:#fbbf24" title="Hosts this session"><i class="fa-solid fa-layer-group" style="font-size:.8rem;color:#1f2937"></i></span>
        @endif
    @elseif($isPlaying)
        <span class="bg-emerald-500" style="width:.55rem;height:.55rem;border-radius:9999px;flex:none" title="Playing"></span>
    @endif
</div>
