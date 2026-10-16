@props(['icon' => null, 'type' => 'button', 'danger' => false])
{{-- One entry of x-menu: a button (type="submit" inside a form). --}}
<button type="{{ $type }}" role="menuitem" {{ $attributes->merge(['class' => 'flex w-full items-center gap-2.5 px-4 py-2 text-left hover:bg-gray-50 dark:hover:bg-stone-700 '.($danger ? 'hover:text-red-500' : '')]) }}>
    @if($icon)<i class="fa-solid fa-{{ $icon }} w-4 text-xs text-gray-400"></i>@endif{{ $slot }}
</button>
