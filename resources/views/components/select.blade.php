@props(['disabled' => false])

<select @disabled($disabled) {{ $attributes->merge(['class' => 'text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 text-gray-700 dark:text-gray-300 rounded-xl px-3 py-2 pr-8 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600 appearance-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed']) }}>
    {{ $slot }}
</select>
