@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 dark:border-stone-700 bg-white dark:bg-stone-800 text-gray-900 dark:text-gray-100 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-stone-500 focus:border-transparent transition-shadow placeholder-gray-400 dark:placeholder-stone-600 disabled:opacity-50']) }}>
