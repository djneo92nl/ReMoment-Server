<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$station->name" :subtitle="$station->genre ?: 'Radio station'">
            <x-slot name="actions">
                <x-secondary-button href="{{ route('radio.index') }}">
                    <i class="fa-solid fa-arrow-left"></i>
                    Radio
                </x-secondary-button>
                <x-secondary-button href="{{ route('radio.edit', $station) }}">
                    <i class="fa-solid fa-pen"></i>
                    Edit
                </x-secondary-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card class="p-5 mb-4">
        <p class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-600 mb-1">Now playing</p>
        <livewire:radio-now-playing :station-id="$station->id" />
    </x-card>

    <x-card class="p-5">
        <p class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-600 mb-3">Recently played on this station</p>
        @forelse($plays as $play)
            @php $track = $tracks[$play->id] ?? null; @endphp
            <div class="flex items-center gap-3 py-2 border-t border-gray-100 dark:border-stone-800 first:border-0">
                <span class="text-xs text-gray-400 dark:text-gray-600 w-12 flex-shrink-0">{{ $play->started_at->format('H:i') }}</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ $play->title }}</p>
                    @if($play->artist)<p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $play->artist }}</p>@endif
                </div>
                @if($track?->album_id)
                    <a href="{{ route('albums.show', $track->album_id) }}" class="text-xs text-emerald-600 dark:text-emerald-400 flex-shrink-0">
                        <i class="fa-solid fa-check mr-1"></i>In library
                    </a>
                @elseif($play->artist)
                    <a href="https://open.spotify.com/search/{{ rawurlencode($play->artist.' '.$play->title) }}" target="_blank" rel="noopener"
                       class="text-xs text-gray-400 dark:text-gray-600 hover:text-gray-600 flex-shrink-0">Find</a>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-400 dark:text-gray-600">Nothing logged yet. Songs are logged while the station's title is being followed (a device plays it, or this page is open).</p>
        @endforelse
    </x-card>
</x-app-layout>
