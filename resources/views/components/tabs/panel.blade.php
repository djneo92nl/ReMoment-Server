@props(['name'])
{{-- The content of one tab of x-tabs; shown while that tab is selected. --}}
<div x-show="tab === '{{ $name }}'" x-cloak role="tabpanel" {{ $attributes }}>{{ $slot }}</div>
