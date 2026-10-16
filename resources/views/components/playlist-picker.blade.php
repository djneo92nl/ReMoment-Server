@props(['playlists'])
{{-- One "Add to playlist" modal for a whole page, for playlists made here (the only editable ones), plus a "New playlist" row.
     Open it with `$dispatch('add-to-playlist', { track_id: 12, label: 'Song' })` or `{ album_id: 3, source: 'dlna', label: 'Album' }`;
     the server adds the track, or the album's tracks of that source in album order, and ignores ones already in. --}}
<div x-data="{ target: { label: '' }, set(d) { this.target = d; $dispatch('open-modal', 'add-to-playlist') } }" @add-to-playlist.window="set($event.detail)">
    <x-modal name="add-to-playlist" maxWidth="md">
        <div class="bg-white dark:bg-stone-900">
            <div class="flex items-start justify-between px-6 pt-6 pb-4">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Add to playlist</h3>
                    <p class="mt-0.5 truncate text-sm text-gray-500 dark:text-gray-400" x-text="target.label"></p>
                </div>
                <x-modal-close name="add-to-playlist" class="ml-4" />
            </div>
            @php
                $hidden = '<input type="hidden" name="track_id" :value="target.track_id" :disabled="!target.track_id"><input type="hidden" name="album_id" :value="target.album_id" :disabled="!target.album_id"><input type="hidden" name="source" :value="target.source" :disabled="!target.source">';
            @endphp
            <div class="flex max-h-[70vh] flex-col gap-2 overflow-y-auto px-4 pb-5">
                <form method="POST" action="{{ route('playlists.store') }}" class="flex gap-2">
                    @csrf
                    {!! $hidden !!}
                    <input type="text" name="name" required maxlength="255" placeholder="New playlist" aria-label="New playlist name"
                           class="min-w-0 flex-1 rounded-2xl border-0 bg-gray-100 px-4 py-3 text-sm text-gray-900 placeholder-gray-400 focus:ring-2 focus:ring-gray-400 dark:bg-stone-800 dark:text-gray-100 dark:focus:ring-stone-500">
                    <button type="submit" class="flex-none rounded-2xl bg-gray-900 px-4 py-3 text-sm font-medium text-white hover:bg-gray-700 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200"><i class="fa-solid fa-plus mr-1.5 text-xs"></i>Create</button>
                </form>
                @foreach($playlists as $playlist)
                    <form method="POST" action="{{ route('playlists.tracks.add', $playlist) }}">
                        @csrf
                        {!! $hidden !!}
                        <button type="submit" class="flex w-full items-center justify-between gap-3 rounded-2xl bg-gray-100 px-4 py-3 text-left text-sm font-medium text-gray-900 transition-colors hover:bg-gray-200 dark:bg-stone-800 dark:text-gray-100 dark:hover:bg-stone-700">
                            <span class="truncate">{{ $playlist->name }}</span>
                            <i class="fa-solid fa-list-ul text-xs text-gray-400"></i>
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    </x-modal>
</div>
