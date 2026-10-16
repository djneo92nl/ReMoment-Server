@props(['tabs', 'active' => null])
{{-- Alpine tabs: `tabs` is key => label (or [label, icon]). Put one x-tabs.panel (name="key") per tab in the slot; the first tab is open
     unless `active` says otherwise. The bar scrolls sideways on phones. Leave out tabs that have no content, and the whole component when none has. --}}
@php $active ??= array_key_first($tabs); @endphp
<div x-data="{ tab: @js($active) }" {{ $attributes }}>
    <div class="no-scrollbar -mx-1 flex gap-1 overflow-x-auto px-1 pb-1" role="tablist">
        @foreach($tabs as $key => $tab)
            @php [$label, $icon] = (array) $tab + [1 => null]; @endphp
            <button type="button" role="tab" @click="tab = '{{ $key }}'" data-test="tab-{{ $key }}"
                    :aria-selected="tab === '{{ $key }}'"
                    :class="tab === '{{ $key }}' ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-stone-800 dark:text-gray-400 dark:hover:bg-stone-700'"
                    class="inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-sm font-medium transition-colors">
                @if($icon)<i class="fa-solid fa-{{ $icon }} text-xs"></i>@endif{{ $label }}
            </button>
        @endforeach
    </div>
    <div class="pt-5">{{ $slot }}</div>
</div>
