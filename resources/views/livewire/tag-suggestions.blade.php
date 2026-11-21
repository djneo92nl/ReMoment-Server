<div class="space-y-4" data-test="tag-suggestions">
    @if($notice)<x-alert type="success">{{ $notice }}</x-alert>@endif
    @if($error)<x-alert type="error">{{ $error }}</x-alert>@endif

    @if($rows === [])
        <x-empty-state icon="circle-check" title="Nothing to review" text="No file or album disagrees with MusicBrainz." />
    @else
        <x-card flush>
            <ul class="divide-y divide-gray-100 dark:divide-stone-800">
                @foreach($rows as $row)
                    <li class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between" wire:key="s-{{ $row['id'] }}" data-test="suggestion-{{ $row['id'] }}">
                        <div class="min-w-0">
                            @if($row['album_id'])
                                <a href="{{ route('albums.show', $row['album_id']) }}" class="text-sm font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $row['title'] }}</a>
                            @else
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $row['title'] }}</p>
                            @endif
                            <p class="mt-0.5 text-xs text-gray-500">{{ $row['reason'] }}</p>
                            @if($row['kind'] === 'tag')
                                <p class="mt-1 break-all text-xs text-gray-500">
                                    <span class="font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">{{ $row['field'] }}</span>
                                    <span class="line-through">{{ $row['current'] ?? '(none)' }}</span> → <span class="text-gray-900 dark:text-gray-100">{{ $row['suggested'] }}</span>
                                </p>
                            @elseif($row['kind'] === 'cover')
                                <p class="mt-1 text-xs text-gray-500">Replace with the front image of the MusicBrainz release (Cover Art Archive).</p>
                            @endif
                        </div>
                        @if($row['can_apply'])
                            <div class="flex shrink-0 gap-2">
                                <x-primary-button size="sm" type="button" wire:click="accept('{{ $row['id'] }}')" wire:loading.attr="disabled" wire:target="accept('{{ $row['id'] }}')"
                                                  wire:confirm="{{ $row['kind'] === 'tag' ? 'This rewrites the tags in the file. Continue?' : 'Replace the album cover?' }}">Accept</x-primary-button>
                                <x-secondary-button size="sm" type="button" wire:click="dismiss('{{ $row['id'] }}')">Dismiss</x-secondary-button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-card>
        @if($total > count($rows))
            <div class="flex items-center gap-3">
                <p class="text-xs text-gray-400 dark:text-gray-600">Showing {{ count($rows) }} of {{ number_format($total) }}.</p>
                <x-secondary-button size="sm" type="button" wire:click="more">Show more</x-secondary-button>
            </div>
        @endif
    @endif
</div>
