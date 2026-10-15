@props(['label', 'mono' => false])
{{-- One label/value line inside a <dl class="space-y-4 text-sm">. `mono` for addresses, ids, keys. --}}
<div class="flex justify-between gap-4">
    <dt @class(['text-gray-500', 'font-mono text-xs' => $mono])>{{ $label }}</dt>
    <dd {{ $attributes->class(['font-medium text-gray-800 dark:text-gray-200 text-right', 'font-mono text-xs break-all' => $mono]) }}>{{ $slot }}</dd>
</div>
