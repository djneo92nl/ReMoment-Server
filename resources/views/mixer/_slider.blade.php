{{-- A slider in the look of the device volume row (card-volume): thin track, fill, an invisible native range on top.
     $value: Alpine expression for the current value; $min/$max/$step: Alpine expressions; $input: handler, `$event.target.value` is the new value; $label: aria-label (Alpine expression). --}}
<div class="relative flex h-10 flex-1 items-center">
    <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-stone-700">
        <div class="h-full rounded-full bg-gray-400 dark:bg-stone-500" :style="'width:' + Math.min(100, Math.max(0, (({{ $value }}) - ({{ $min }})) / (({{ $max }}) - ({{ $min }})) * 100)) + '%'"></div>
    </div>
    <input type="range" :min="{{ $min }}" :max="{{ $max }}" :step="{{ $step }}" :value="{{ $value }}" @input="{{ $input }}" :aria-label="{{ $label }}"
           class="absolute inset-0 h-full w-full cursor-pointer opacity-0">
</div>
