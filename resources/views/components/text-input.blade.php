@props(['disabled' => false, 'invalid' => false, 'size' => 'md'])
@php($pad = $size === 'sm' ? 'px-3 py-1.5' : 'px-4 py-2.5')
@php($border = $invalid ? 'border-red-400' : 'border-gray-200 dark:border-stone-700')

<input @disabled($disabled) {{ $attributes->merge(['class' => 'rounded-xl border '.$border.' bg-white dark:bg-stone-800 text-gray-900 dark:text-gray-100 '.$pad.' text-sm focus:outline-none focus:ring-2 focus:ring-gray-900 dark:focus:ring-stone-500 focus:border-transparent transition-shadow placeholder-gray-400 dark:placeholder-stone-600 disabled:opacity-50']) }}>
