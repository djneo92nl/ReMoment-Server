@props(['name' => null])

{{-- The × of a modal header. With a name it closes that modal (a modal opened by name), without it the modal around it. --}}
<button type="button" @click="{{ $name ? "\$dispatch('close-modal', '{$name}')" : "\$dispatch('close')" }}"
        {{ $attributes->merge(['class' => 'flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 dark:text-gray-600 hover:bg-gray-100 dark:hover:bg-stone-800 hover:text-gray-600 dark:hover:text-gray-300 transition-colors flex-shrink-0']) }}>
    <i class="fa-solid fa-xmark text-xs"></i>
</button>
