@props(['size' => 'lg'])
{{-- "Re × Moment". `lg` sidebar/drawer, `xl` sign-in page. --}}
@php($text = $size === 'xl' ? 'text-3xl' : 'text-2xl')
<div {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    <span class="{{ $text }} font-medium tracking-tight text-gray-900 dark:text-gray-100">Re</span>
    <span class="{{ $size === 'xl' ? 'text-xl' : 'text-base' }} text-gray-400">×</span>
    <span class="{{ $text }} font-light text-red-600">Moment</span>
</div>
