@props(['on' => false, 'label' => ''])

{{-- Size and knob position are inline: Tailwind runs from the CDN here, and a class that only
     appears after a Livewire lazy load (w-11, translate-x-5, …) is not generated in time. --}}
<button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ $label }}"
        style="width:2.75rem;height:1.5rem"
        {{ $attributes->merge(['class' => 'relative inline-flex flex-shrink-0 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600 disabled:opacity-50 '.($on ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-stone-700')]) }}>
    <span class="inline-block rounded-full bg-white shadow transition-transform"
          style="width:1.25rem;height:1.25rem;transform:translateX({{ $on ? '1.375rem' : '0.125rem' }})"></span>
</button>
