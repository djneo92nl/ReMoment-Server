<x-card data-test="storage-files">
    @php
                $menuItem = 'block w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-stone-700';
    @endphp

    @if(session('files-success') || session('files-error'))
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 6000)" x-show="show" x-transition
             style="position: fixed; right: 1.5rem; bottom: 1.5rem; z-index: 60; max-width: 24rem;"
             class="px-5 py-3 text-sm rounded-2xl shadow-xl border {{ session('files-error') ? 'text-red-800 dark:text-red-200 bg-red-50 dark:bg-red-950 border-red-200 dark:border-red-800' : 'text-emerald-800 dark:text-emerald-200 bg-emerald-50 dark:bg-emerald-950 border-emerald-200 dark:border-emerald-800' }}"
             data-test="files-toast">
            <i class="fa-solid {{ session('files-error') ? 'fa-circle-exclamation' : 'fa-circle-check' }} mr-2"></i>{{ session('files-error') ?? session('files-success') }}
        </div>
    @endif

    <div class="px-6 py-5 border-b border-gray-100 dark:border-stone-800">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Files</h2>
            <p class="text-sm text-gray-500">
                {{ number_format($total) }} {{ $total === 1 ? 'file' : 'files' }}
                @if($missing > 0)&middot; <button wire:click="$set('statusFilter', 'missing')" class="text-amber-500 hover:underline">{{ $missing }} missing</button> @endif
                @if($hiddenCount > 0)&middot; <button wire:click="$set('statusFilter', 'hidden')" class="hover:underline">{{ $hiddenCount }} hidden</button> @endif
            </p>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <x-text-input size="sm" type="search" wire:model.live.debounce.300ms="search" placeholder="Search path…" data-test="file-search" class="flex-1 min-w-48" />
            <x-select size="sm" wire:model.live="locationId"  data-test="location-select">
                @foreach($locations as $loc)<option value="{{ $loc->id }}" @selected($location && $location->id === $loc->id)>{{ $loc->name }}</option>@endforeach
            </x-select>
            <x-select size="sm" wire:model.live="statusFilter"  data-test="status-select">
                <option value="">All files</option>
                @foreach(\App\Livewire\StorageFiles::STATUSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </x-select>
        </div>

        @if(!$flat && $location)
            <nav class="mt-4 flex flex-wrap items-center gap-1.5 text-sm" data-test="breadcrumbs">
                <button wire:click="open('')" class="font-medium {{ $crumbs === [] ? 'text-gray-900 dark:text-gray-100' : 'text-gray-900 dark:text-gray-100 underline' }}"><i class="fa-solid fa-hard-drive mr-1"></i>{{ $location->name }}</button>
                @foreach($crumbs as $crumb)
                    <span class="text-gray-300 dark:text-stone-600">/</span>
                    <button wire:click="open(@js($crumb['path']))" class="{{ $loop->last ? 'text-gray-900 dark:text-gray-100 font-medium' : 'text-gray-900 dark:text-gray-100 underline' }}">{{ $crumb['name'] }}</button>
                @endforeach
            </nav>
        @endif
    </div>

    @if($folders->isEmpty() && $files->isEmpty())
        <div class="px-6 py-12 text-sm text-gray-400 dark:text-gray-600 text-center">{{ $location ? 'No files here.' : 'Add a storage location first.' }}</div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-400 dark:text-gray-600 border-b border-gray-100 dark:border-stone-800">
                        <th class="px-6 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium hidden md:table-cell">Track</th>
                        <th class="px-3 py-2 font-medium hidden sm:table-cell">Size</th>
                        <th class="px-3 py-2 font-medium hidden lg:table-cell">Format</th>
                        <th class="px-3 py-2 font-medium">State</th>
                        <th class="px-3 py-2 w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50 dark:divide-stone-800/50">
                    @foreach($folders as $folder)
                        @php($folderPath = ltrim($this->dir.'/'.$folder->name, '/'))
                        <tr class="hover:bg-gray-50 dark:hover:bg-stone-800/40" wire:key="folder-{{ $folderPath }}" data-test="folder-{{ $folder->name }}">
                            <td class="px-6 py-2.5">
                                <button wire:click="open(@js($folderPath))" class="flex items-center gap-2.5 text-left text-gray-900 dark:text-gray-100 hover:underline">
                                    <i class="fa-solid fa-folder text-amber-400"></i><span class="truncate">{{ $folder->name }}</span>
                                </button>
                            </td>
                            <td class="px-3 py-2.5 hidden md:table-cell text-xs text-gray-500">{{ number_format($folder->files) }} {{ $folder->files == 1 ? 'file' : 'files' }}</td>
                            <td class="px-3 py-2.5 hidden sm:table-cell text-xs text-gray-500">{{ \Illuminate\Support\Number::fileSize((int) $folder->bytes) }}</td>
                            <td class="px-3 py-2.5 hidden lg:table-cell"></td>
                            <td class="px-3 py-2.5 space-x-1">
                                @if($folder->missing > 0)<x-badge variant="warning" class="whitespace-nowrap">{{ $folder->missing }} missing</x-badge>@endif
                                @if($folder->hidden > 0)<x-badge class="whitespace-nowrap">{{ $folder->hidden == $folder->files ? 'hidden' : $folder->hidden.' hidden' }}</x-badge>@endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="relative inline-block" x-data="{ open: false }" @click.outside="open = false">
                                    <button @click="open = !open" class="w-8 h-8 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 rounded-xl" aria-label="Folder actions"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                    <div x-show="open" x-cloak @click="open = false" style="position: absolute; right: 0; z-index: 40; min-width: 14rem;"
                                         class="mt-1 py-1 text-left bg-white dark:bg-stone-800 rounded-xl shadow-xl border border-gray-200 dark:border-stone-700">
                                        <button wire:click="setFolderHidden(@js($folderPath), true)" class="{{ $menuItem }}"><i class="fa-regular fa-eye-slash w-5"></i>Hide folder from library</button>
                                        <button wire:click="setFolderHidden(@js($folderPath), false)" class="{{ $menuItem }}"><i class="fa-regular fa-eye w-5"></i>Show folder in library</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    @foreach($files as $file)
                        @php($info = $parsed[$file->id])
                        @php($track = $info['track'])
                        <tr class="hover:bg-gray-50 dark:hover:bg-stone-800/40 {{ $file->isHidden() ? 'opacity-60' : '' }}" data-test="file-{{ $file->id }}" wire:key="file-{{ $file->id }}">
                            <td class="px-6 py-2.5 max-w-xs">
                                <p class="flex items-center gap-2.5 text-gray-900 dark:text-gray-100" title="{{ $file->path }}">
                                    <i class="fa-solid fa-music text-gray-300 dark:text-stone-600"></i>
                                    <span class="truncate font-mono text-[13px]">
                                        @if($flat && str_contains($file->path, '/'))<span class="text-gray-400">{{ dirname($file->path) }}/</span>@endif{{ basename($file->path) }}
                                    </span>
                                </p>
                            </td>
                            <td class="px-3 py-2.5 hidden md:table-cell text-xs text-gray-500 max-w-[14rem] truncate">{{ $track?->name ?? '—' }}</td>
                            <td class="px-3 py-2.5 hidden sm:table-cell text-xs text-gray-500 whitespace-nowrap">{{ \Illuminate\Support\Number::fileSize($file->size) }}</td>
                            <td class="px-3 py-2.5 hidden lg:table-cell text-xs text-gray-500 whitespace-nowrap">
                                {{ $file->codec }}@if($file->bitrate) {{ round($file->bitrate / 1000) }} kbps @endif
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="flex flex-wrap gap-1">
                                    @if($file->status === 'missing')
                                        <x-badge variant="warning" class="whitespace-nowrap" data-test="chip-missing">missing</x-badge>
                                    @endif
                                    @if($track)
                                        <x-badge variant="success" class="whitespace-nowrap" title="Tags read and matched to a track" data-test="chip-parsed"><i class="fa-solid fa-check"></i>parsed</x-badge>
                                        @if($info['pending'] === [])
                                            <x-badge variant="success" class="whitespace-nowrap" data-test="chip-enriched"><i class="fa-solid fa-check"></i>enriched</x-badge>
                                        @else
                                            <x-badge variant="info" class="whitespace-nowrap" title="Waiting for: {{ implode(', ', $info['pending']) }}" data-test="chip-enriched"><i class="fa-solid fa-hourglass-half"></i>{{ count($info['pending']) }} to fetch</x-badge>
                                        @endif
                                    @else
                                        <x-badge class="whitespace-nowrap" data-test="chip-parsed">not parsed</x-badge>
                                    @endif
                                    @if($track && \App\Domain\Analysis\AudioAnalysis::enabled())
                                        <x-analysis-dots :analysis="$file->analysis" :hash="$file->hash" data-test="chip-analysis" />
                                    @endif
                                    @if($file->isHidden())
                                        <x-badge class="whitespace-nowrap" data-test="chip-hidden"><i class="fa-regular fa-eye-slash"></i>hidden</x-badge>
                                    @endif
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="relative inline-block" x-data="{ open: false }" @click.outside="open = false">
                                    <button @click="open = !open" class="w-8 h-8 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 rounded-xl" aria-label="File actions" data-test="menu-{{ $file->id }}"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                    <div x-show="open" x-cloak @click="open = false" style="position: absolute; right: 0; z-index: 40; min-width: 14rem;"
                                         class="mt-1 py-1 text-left bg-white dark:bg-stone-800 rounded-xl shadow-xl border border-gray-200 dark:border-stone-700">
                                        <button wire:click="startEdit({{ $file->id }})" class="{{ $menuItem }}"><i class="fa-solid fa-tags w-5"></i>Edit tags…</button>
                                        <button wire:click="toggleHidden({{ $file->id }})" class="{{ $menuItem }}"><i class="fa-regular {{ $file->isHidden() ? 'fa-eye' : 'fa-eye-slash' }} w-5"></i>{{ $file->isHidden() ? 'Show in library' : 'Hide from library' }}</button>
                                        <button wire:click="rematch({{ $file->id }})" class="{{ $menuItem }}"><i class="fa-solid fa-link w-5"></i>Match to track again</button>
                                        <button wire:click="enrich({{ $file->id }})" class="{{ $menuItem }}"><i class="fa-solid fa-wand-magic-sparkles w-5"></i>Fetch metadata again</button>
                                        <button wire:click="verify({{ $file->id }})" class="{{ $menuItem }}"><i class="fa-solid fa-circle-check w-5"></i>Verify</button>
                                        <button wire:click="delete({{ $file->id }})" wire:confirm="Delete this file from storage? This cannot be undone." class="{{ $menuItem }} text-red-600 dark:text-red-400"><i class="fa-solid fa-trash w-5"></i>Delete</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4">{{ $files->links() }}</div>
    @endif

    @if($editing !== null)
        <div style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0,0,0,.5);" data-test="tag-editor"
             wire:keydown.escape="cancelEdit">
            <div class="w-full max-w-xl max-h-full overflow-y-auto bg-white dark:bg-stone-900 rounded-3xl shadow-2xl border border-gray-200 dark:border-stone-700 p-6 space-y-4">
                <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Edit tags</h3>
                    <p class="text-xs text-gray-500 mt-0.5">The tags are written into the file, which is then matched to its track again, moved to its new path and looked up again by the metadata sources.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach(['title' => 'Title', 'artist' => 'Artist', 'album' => 'Album', 'album_artist' => 'Album artist', 'year' => 'Year', 'genre' => 'Genre', 'track_number' => 'Track number', 'disc_number' => 'Disc number'] as $key => $label)
                        <div class="{{ in_array($key, ['title']) ? 'sm:col-span-2' : '' }}">
                            <label class="block text-xs font-medium text-gray-500 mb-1">{{ $label }}</label>
                            <x-text-input size="sm" type="text" wire:model="tags.{{ $key }}" wire:keydown.enter="saveTags" data-test="tag-{{ $key }}" class="w-full" />
                        </div>
                    @endforeach
                </div>
                <div class="rounded-2xl border border-gray-200 dark:border-stone-700 p-4 space-y-3" data-test="suggestions">
                    <div>
                        <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">What could this be?</h4>
                        <p class="text-xs text-gray-500 mt-0.5">Give a hint and pick a candidate: it fills the tags above, nothing is written until you press Write tags.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-text-input size="sm" type="text" wire:model="cueTitle" wire:keydown.enter="suggest" placeholder="Title" data-test="cue-title" class="flex-1 min-w-40" />
                        <x-text-input size="sm" type="text" wire:model="cueArtist" wire:keydown.enter="suggest" placeholder="Artist (optional)" data-test="cue-artist" class="flex-1 min-w-40" />
                        <x-secondary-button size="sm" wire:click="suggest" wire:loading.attr="disabled" data-test="suggest"><i class="fa-solid fa-magnifying-glass mr-2"></i>Search</x-secondary-button>
                    </div>
                    @if($suggestions['error'])<p class="text-xs text-red-500">{{ $suggestions['error'] }}</p>@endif
                    @foreach(['library' => 'In your library', 'musicbrainz' => 'MusicBrainz'] as $kind => $heading)
                        @if($suggestions[$kind] !== [])
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-1">{{ $heading }}</div>
                                <ul class="divide-y divide-gray-100 dark:divide-stone-800">
                                    @foreach($suggestions[$kind] as $i => $candidate)
                                        <li class="flex items-center gap-3 py-1.5" data-test="candidate-{{ $kind }}-{{ $i }}">
                                            <div class="min-w-0 flex-1 text-sm">
                                                <div class="truncate text-gray-900 dark:text-gray-100">{{ $candidate['title'] }} <span class="text-gray-500">· {{ $candidate['artist'] }}</span></div>
                                                <div class="truncate text-xs text-gray-500">{{ $candidate['album'] ?? 'No album' }}@if($candidate['year']) · {{ $candidate['year'] }}@endif · {{ $candidate['detail'] }}</div>
                                            </div>
                                            <button wire:click="useSuggestion('{{ $kind }}', {{ $i }})" class="text-xs text-gray-900 dark:text-gray-100 underline whitespace-nowrap">Use</button>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                    @if($suggestions['library'] === [] && $suggestions['musicbrainz'] === [] && $suggestions['searched'])
                        <p class="text-xs text-gray-500">Nothing found. Try a shorter title or leave the artist out.</p>
                    @endif
                </div>
                <div class="flex items-center justify-end gap-2 pt-2">
                    <button wire:click="cancelEdit" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-200">Cancel</button>
                    <x-primary-button size="md" wire:click="saveTags" wire:loading.attr="disabled">Write tags</x-primary-button>
                </div>
            </div>
        </div>
    @endif
</x-card>
