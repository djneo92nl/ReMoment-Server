{{-- Data table in a card. `head` slot = <th> cells (use <x-table.th>), default slot = <tr> rows. --}}
<x-card flush {{ $attributes }}>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 dark:border-stone-800">{{ $head }}</tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-stone-800">{{ $slot }}</tbody>
        </table>
    </div>
</x-card>
