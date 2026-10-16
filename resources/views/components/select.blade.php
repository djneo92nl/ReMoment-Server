@props(['disabled' => false, 'invalid' => false, 'size' => 'md'])
@php($pad = $size === 'sm' ? 'px-3 py-1.5' : 'px-3 py-2')
@php($border = $invalid ? 'border-red-400' : 'border-gray-200 dark:border-stone-700')

<select @disabled($disabled) {{ $attributes->merge(['class' => 'text-sm bg-gray-50 dark:bg-stone-800 border '.$border.' text-gray-700 dark:text-gray-300 rounded-xl '.$pad.' pr-8 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600 appearance-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed']) }}>
    {{ $slot }}
</select>
