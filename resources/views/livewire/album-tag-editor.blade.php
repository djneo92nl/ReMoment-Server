<div class="space-y-6" data-test="album-tag-editor">
    @if($notice)<x-alert type="success" data-test="album-notice">{{ $notice }}</x-alert>@endif
    @if($error)<x-alert type="error" data-test="album-error">{{ $error }}</x-alert>@endif

    @if($artworkReasons)
        <x-alert type="warning" data-test="album-no-artwork">
            <p class="font-medium">Why this album has no cover</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-4">@foreach($artworkReasons as $reason)<li>{{ $reason }}</li>@endforeach</ul>
        </x-alert>
    @endif

    @if($rows === [])
        <x-empty-state icon="compact-disc" title="No files of your own" :text="$albumName.' has no files in a storage location, so there is nothing to write tags into.'" />
    @else
        @unless($anyEditable)
            <x-alert type="warning" data-test="album-not-editable">None of this album's files can be edited: they are in the inbox (the inbox is never changed; import them into the library first), in a format whose tags cannot be written, or ffmpeg is missing.</x-alert>
        @endunless

        <x-card class="p-6 space-y-4">
            <div>
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $albumName }}</h2>
                <p class="mt-0.5 text-xs text-gray-500">Album fields are written into every file. A field the files disagree on is left empty here and only written when you type in it. Each file is rewritten as a copy and swapped in, so a failure leaves it as it was.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach(['album' => 'Album', 'album_artist' => 'Album artist', 'year' => 'Year', 'genre' => 'Genre'] as $key => $label)
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-500">{{ $label }}</label>
                        <x-text-input size="sm" type="text" wire:model="fields.{{ $key }}" :placeholder="in_array($key, $mixed, true) ? 'Differs per file' : ''" data-test="album-{{ $key }}" class="w-full" />
                    </div>
                @endforeach
            </div>
        </x-card>

        <x-card class="p-6 space-y-4" data-test="album-suggestions" wire:init="lookup">
            <div>
                <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">What could this album be?</h3>
                <p class="mt-0.5 text-xs text-gray-500">From MusicBrainz and the Cover Art Archive. Picking one fills the fields above and the cover; nothing is written until you press Save.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-text-input size="sm" type="text" wire:model="cueArtist" wire:keydown.enter="lookup" placeholder="Artist" data-test="cue-artist" class="flex-1 min-w-40" />
                <x-text-input size="sm" type="text" wire:model="cueAlbum" wire:keydown.enter="lookup" placeholder="Album" data-test="cue-album" class="flex-1 min-w-40" />
                <x-secondary-button size="sm" wire:click="lookup" wire:loading.attr="disabled" wire:target="lookup" data-test="lookup"><i class="fa-solid fa-magnifying-glass mr-2"></i><span wire:loading.remove wire:target="lookup">Search</span><span wire:loading wire:target="lookup">Searching…</span></x-secondary-button>
            </div>
            @if($suggestions['error'])<p class="text-xs text-red-500">{{ $suggestions['error'] }}</p>@endif
            @if($suggestions['releases'] !== [])
                <div>
                    <div class="mb-2 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Matching releases</div>
                    <ul class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach($suggestions['releases'] as $i => $c)
                            <li class="flex items-center gap-3 rounded-xl border border-gray-100 p-2 dark:border-stone-800" data-test="release-{{ $i }}">
                                <x-artwork-thumb :src="$c['cover']" :seed="$c['title']" icon="fa-solid fa-compact-disc" size="h-14 w-14" />
                                <div class="min-w-0 flex-1 text-sm">
                                    <div class="truncate text-gray-900 dark:text-gray-100">{{ $c['title'] }}</div>
                                    <div class="truncate text-xs text-gray-500">{{ $c['artist'] }}@if($c['year']) · {{ $c['year'] }}@endif</div>
                                    <div class="truncate text-xs text-gray-400">{{ $c['detail'] }}</div>
                                </div>
                                <div class="flex flex-col gap-0.5 text-xs">
                                    <button wire:click="use('releases', {{ $i }}, 'both')" class="underline">Use</button>
                                    <button wire:click="use('releases', {{ $i }}, 'cover')" class="text-gray-500 underline">Cover only</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if($suggestions['artist'])
                <div data-test="artist-albums">
                    <div class="mb-2 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Known albums of {{ $suggestions['artist']['name'] }}</div>
                    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach($suggestions['artist']['albums'] as $i => $c)
                            <li class="space-y-1 text-xs" data-test="artist-album-{{ $i }}">
                                <x-artwork-thumb :src="$c['cover']" :seed="$c['title']" icon="fa-solid fa-compact-disc" size="aspect-square w-full" rounded="rounded-xl" />
                                <div class="truncate text-sm text-gray-900 dark:text-gray-100" title="{{ $c['title'] }}">{{ $c['title'] }}</div>
                                <div class="truncate text-gray-500">@if($c['year']){{ $c['year'] }} · @endif{{ $c['detail'] }}</div>
                                <div class="flex gap-2"><button wire:click="use('albums', {{ $i }}, 'both')" class="underline">Use</button><button wire:click="use('albums', {{ $i }}, 'cover')" class="text-gray-500 underline">Cover only</button></div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if($suggestions['searched'] && !$suggestions['error'] && $suggestions['releases'] === [] && !$suggestions['artist'])
                <p class="text-xs text-gray-500">Nothing found. Try a shorter album name or another spelling of the artist.</p>
            @endif
        </x-card>

        <x-card class="p-6 space-y-3" data-test="album-cover">
            <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">Cover</h3>
            <p class="text-xs text-gray-500">A new picture is embedded in every file and becomes the album's picture.</p>
            <div class="flex flex-wrap items-center gap-3">
                @if($coverUrl && !$cover)
                    <div class="flex items-center gap-2" data-test="album-cover-picked">
                        <x-artwork-thumb :src="$coverUrl" :seed="$albumName" icon="fa-solid fa-compact-disc" size="h-16 w-16" />
                        <div class="text-xs text-gray-500">From the Cover Art Archive <button wire:click="clearCoverUrl" class="ml-1 underline">remove</button></div>
                    </div>
                @endif
                @if($cover && !$errors->has('cover'))
                    <img src="{{ $cover->temporaryUrl() }}" alt="The new cover" class="h-16 w-16 rounded-xl object-cover" data-test="album-cover-preview">
                @endif
                <label class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 px-4 py-2.5 text-sm text-gray-500 transition hover:border-gray-400 hover:text-gray-700 dark:border-stone-700 dark:hover:border-stone-500 dark:hover:text-gray-300">
                    <i class="fa-solid fa-arrow-up-from-bracket text-xs"></i>
                    <span wire:loading.remove wire:target="cover">{{ $cover ? 'Pick another picture' : 'Pick a JPEG or PNG' }}</span>
                    <span wire:loading wire:target="cover">Uploading…</span>
                    <input type="file" wire:model="cover" accept="image/jpeg,image/png" class="sr-only" data-test="album-cover-input">
                </label>
            </div>
            @error('cover')<p class="text-xs text-red-500">{{ $message }}</p>@enderror

            <div class="space-y-3 border-t border-gray-100 pt-3 dark:border-stone-800">
                <x-secondary-button size="sm" wire:click="searchCovers" wire:loading.attr="disabled" wire:target="searchCovers" data-test="search-covers">
                    <i class="fa-solid fa-magnifying-glass mr-2"></i><span wire:loading.remove wire:target="searchCovers">Search for artwork</span><span wire:loading wire:target="searchCovers">Searching…</span>
                </x-secondary-button>
                <span class="ml-2 text-xs text-gray-500">Uses the album and album artist as typed above.</span>
                @if($coverSearchError)<p class="text-xs text-red-500">{{ $coverSearchError }}</p>@endif
                @if($coverResults)
                    <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6" data-test="cover-results">
                        @foreach($coverResults as $i => $c)
                            <li wire:key="cover-result-{{ $i }}" class="space-y-1 text-xs">
                                <button type="button" wire:click="useCover({{ $i }})" title="Use this cover" class="block w-full {{ $coverUrl === $c['cover'] ? 'ring-2 ring-gray-900 dark:ring-white' : '' }} rounded-xl">
                                    <x-artwork-thumb :src="$c['cover']" :seed="$c['title']" icon="fa-solid fa-compact-disc" size="aspect-square w-full" rounded="rounded-xl" />
                                </button>
                                <div class="truncate text-gray-900 dark:text-gray-100" title="{{ $c['title'] }}">{{ $c['title'] }}</div>
                                <div class="truncate text-gray-500">{{ $c['artist'] }}@if($c['year']) · {{ $c['year'] }}@endif</div>
                            </li>
                        @endforeach
                    </ul>
                @elseif($coverResults !== null && !$coverSearchError)
                    <p class="text-xs text-gray-500">No release found. Change the album or album artist above and search again.</p>
                @endif
            </div>
        </x-card>

        <x-card class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-test="album-files">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">
                            <th class="px-4 py-3 w-16">Disc</th><th class="px-2 py-3 w-16">No.</th><th class="px-2 py-3">Title</th><th class="px-2 py-3">Artist</th><th class="px-4 py-3 hidden lg:table-cell">File</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-stone-800">
                        @foreach($rows as $id => $row)
                            <tr wire:key="row-{{ $id }}" data-test="album-file-{{ $id }}">
                                <td class="px-4 py-2"><x-text-input size="sm" type="text" inputmode="numeric" wire:model="rows.{{ $id }}.disc_number" :disabled="!$row['editable']" aria-label="Disc number" class="w-14" /></td>
                                <td class="px-2 py-2"><x-text-input size="sm" type="text" inputmode="numeric" wire:model="rows.{{ $id }}.track_number" :disabled="!$row['editable']" aria-label="Track number" data-test="row-{{ $id }}-number" class="w-14" /></td>
                                <td class="px-2 py-2"><x-text-input size="sm" type="text" wire:model="rows.{{ $id }}.title" :disabled="!$row['editable']" aria-label="Title" data-test="row-{{ $id }}-title" class="w-full min-w-40" /></td>
                                <td class="px-2 py-2"><x-text-input size="sm" type="text" wire:model="rows.{{ $id }}.artist" :disabled="!$row['editable']" aria-label="Artist" class="w-full min-w-32" /></td>
                                <td class="px-4 py-2 hidden lg:table-cell max-w-[18rem] truncate text-xs text-gray-500" title="{{ $row['path'] }}">{{ $row['path'] }}@unless($row['editable']) <x-badge class="ml-1">read only</x-badge>@endunless</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <x-toggle :on="$organize" label="Move the files to the library layout" wire:click="$toggle('organize')" data-test="album-organize" />
                Move the files to the library layout for their new tags
            </div>
            <div class="ml-auto flex items-center gap-2">
                @if($canUndo)
                    <x-secondary-button size="md" wire:click="undo" wire:confirm="Put every file of this album back as it was before its last edit?" data-test="album-undo"><i class="fa-solid fa-rotate-left mr-2"></i>Undo the last edit</x-secondary-button>
                @endif
                <x-primary-button size="md" wire:click="save" wire:loading.attr="disabled" :disabled="!$anyEditable" data-test="album-save">Save</x-primary-button>
            </div>
        </div>
    @endif
</div>
