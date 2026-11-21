<div class="space-y-6">
    {{-- Add: a barcode finds the release on MusicBrainz, or fill the form in by hand (tapes, bootlegs) --}}
    <x-card class="p-5 sm:p-6">
        <h2 class="mb-4 text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600"><i class="fa-solid fa-plus mr-1.5"></i>Add to the collection</h2>
        <form wire:submit="lookup" class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <x-input-label for="barcode" value="Barcode" />
                <x-text-input id="barcode" wire:model="barcode" inputmode="numeric" placeholder="e.g. 0602537531159" class="w-full" data-test="barcode" />
                <x-input-error :messages="$errors->get('barcode')" class="mt-1" />
            </div>
            <x-secondary-button type="submit" size="md"><i class="fa-solid fa-magnifying-glass"></i>Find release</x-secondary-button>
        </form>
        @if($message)<p class="mt-3 text-sm text-gray-500" role="status">{{ $message }}</p>@endif

        @if($candidates)
            <ul class="mt-4 divide-y divide-gray-100 rounded-2xl border border-gray-100 dark:divide-stone-800/70 dark:border-stone-800" data-test="candidates">
                @foreach($candidates as $i => $c)
                    <li class="flex items-center gap-3 px-4 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $c['title'] }}</p>
                            <p class="truncate text-xs text-gray-400 dark:text-gray-600">{{ $c['artist'] }}{{ $c['year'] ? ' · '.$c['year'] : '' }}{{ $c['label'] ? ' · '.$c['label'] : '' }}{{ $c['country'] ? ' · '.$c['country'] : '' }} · {{ strtoupper($c['medium']) }}</p>
                        </div>
                        <x-secondary-button type="button" size="sm" wire:click="choose({{ $i }})">Use this</x-secondary-button>
                    </li>
                @endforeach
            </ul>
        @endif

        <form wire:submit="save" class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div><x-input-label for="artist" value="Artist" /><x-text-input id="artist" wire:model="artist" class="w-full" data-test="artist" /><x-input-error :messages="$errors->get('artist')" class="mt-1" /></div>
            <div><x-input-label for="title" value="Title" /><x-text-input id="title" wire:model="title" class="w-full" data-test="title" /><x-input-error :messages="$errors->get('title')" class="mt-1" /></div>
            <div><x-input-label for="year" value="Year" /><x-text-input id="year" wire:model="year" inputmode="numeric" class="w-full" /><x-input-error :messages="$errors->get('year')" class="mt-1" /></div>
            <div><x-input-label for="newMedium" value="Medium" />
                <x-select id="newMedium" wire:model="newMedium" class="w-full">@foreach($media as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach</x-select></div>
            <div><x-input-label for="location" value="Where it is (shelf, box, changer slot)" /><x-text-input id="location" wire:model="location" class="w-full" placeholder="Shelf B2" /></div>
            <div class="flex items-end"><x-primary-button type="submit" size="md" data-test="save"><i class="fa-solid fa-plus"></i>Add</x-primary-button></div>
        </form>
    </x-card>

    {{-- Import: a CSV of the collection (Discogs export or any file with artist, title, medium ...), previewed row by row before anything is added --}}
    <x-card class="p-5 sm:p-6" data-test="import">
        <h2 class="mb-4 text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600"><i class="fa-solid fa-file-csv mr-1.5"></i>Import a CSV</h2>
        @if($importError)<x-alert type="error" class="mb-3">{{ $importError }}</x-alert>@endif

        @if($importToken === null)
            <form wire:submit="previewImport" class="space-y-3">
                <p class="text-sm text-gray-500">A Discogs collection export works as it is. Any other file needs a first line with column names such as artist, title, medium (or format), year, barcode and location. A row with only a barcode is looked up on MusicBrainz.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <x-input-label for="csv-file" value="CSV file" />
                        <input id="csv-file" type="file" wire:model="csvFile" accept=".csv,.tsv,.txt,text/csv,text/plain" class="block w-full text-sm text-gray-700 dark:text-gray-300" data-test="csv-file" />
                        <x-input-error :messages="$errors->get('csvFile')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="import-medium" value="Medium of rows that have none" />
                        <x-select id="import-medium" wire:model="importMedium" class="w-full"><option value="">None: skip those rows</option>@foreach($media as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach</x-select>
                    </div>
                </div>
                <div>
                    <x-input-label for="csv-text" value="Or paste the text" />
                    <textarea id="csv-text" wire:model="csvText" rows="4" placeholder="artist,title,medium,year,barcode,location" data-test="csv-text"
                              class="w-full rounded-xl border-gray-300 bg-white font-mono text-xs shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-stone-700 dark:bg-stone-900 dark:text-gray-100"></textarea>
                </div>
                <x-secondary-button type="submit" size="md" data-test="preview"><i class="fa-solid fa-eye"></i>Preview</x-secondary-button>
            </form>
        @else
            <div class="space-y-3" data-test="preview-result">
                <p class="text-sm text-gray-500">{{ $importFormat === 'discogs' ? 'Discogs collection export.' : 'CSV file.' }} {{ number_format($importSummary['rows'] ?? 0) }} rows. Nothing has been added yet.@if($importUnmapped) Columns not used: {{ implode(', ', $importUnmapped) }}.@endif</p>
                <div class="flex flex-wrap items-center gap-2">
                    @foreach(['new' => ['success', 'New'], 'lookup' => ['info', 'Look up barcode'], 'duplicate' => ['neutral', 'Duplicate'], 'invalid' => ['danger', 'Invalid']] as $status => [$variant, $label])
                        <button type="button" wire:click="$set('importFilter', '{{ $importFilter === $status ? '' : $status }}')" class="rounded-full ring-offset-2 {{ $importFilter === $status ? 'ring-2 ring-gray-400' : '' }}" data-test="filter-{{ $status }}">
                            <x-badge :variant="$variant">{{ $label }}: {{ number_format($importSummary[$status] ?? 0) }}</x-badge>
                        </button>
                    @endforeach
                </div>
                <x-table>
                    <x-slot:head><x-table.th>Row</x-table.th><x-table.th>Status</x-table.th><x-table.th>Artist</x-table.th><x-table.th>Title</x-table.th><x-table.th hide="sm">Medium</x-table.th><x-table.th hide="md">Barcode</x-table.th><x-table.th hide="md">Note</x-table.th></x-slot:head>
                    @foreach($importRows as $r)
                        <tr data-test="import-row-{{ $r['row'] }}">
                            <td class="px-4 py-2.5 text-gray-400">{{ $r['row'] }}</td>
                            <td class="px-4 py-2.5"><x-badge :variant="['new' => 'success', 'lookup' => 'info', 'duplicate' => 'neutral', 'invalid' => 'danger'][$r['status']]">{{ ['new' => 'New', 'lookup' => 'Look up', 'duplicate' => 'Duplicate', 'invalid' => 'Invalid'][$r['status']] }}</x-badge></td>
                            <td class="px-4 py-2.5 text-gray-900 dark:text-gray-100">{{ $r['artist'] ?? 'from MusicBrainz' }}</td>
                            <td class="px-4 py-2.5 text-gray-900 dark:text-gray-100">{{ $r['title'] ?? 'from MusicBrainz' }}</td>
                            <td class="hidden px-4 py-2.5 text-gray-500 sm:table-cell">{{ $r['medium'] ? ucfirst($r['medium']) : '' }}</td>
                            <td class="hidden px-4 py-2.5 text-gray-500 md:table-cell">{{ $r['barcode'] }}</td>
                            <td class="hidden px-4 py-2.5 text-xs text-gray-400 md:table-cell">{{ $r['reason'] }}</td>
                        </tr>
                    @endforeach
                </x-table>
                @if(($importSummary[$importFilter ?: 'rows'] ?? 0) > count($importRows))<p class="text-xs text-gray-400">The first {{ count($importRows) }} rows are shown; all of them are imported.</p>@endif
                <div class="flex flex-wrap gap-2">
                    @php($importable = ($importSummary['new'] ?? 0) + ($importSummary['lookup'] ?? 0))
                    <x-primary-button type="button" size="md" wire:click="confirmImport" :disabled="$importable === 0" data-test="confirm-import"><i class="fa-solid fa-check"></i>Import {{ number_format($importable) }} {{ $importable === 1 ? 'row' : 'rows' }}</x-primary-button>
                    <x-secondary-button type="button" size="md" wire:click="cancelImport">Cancel</x-secondary-button>
                </div>
            </div>
        @endif
    </x-card>

    <div class="flex flex-wrap items-center gap-3">
        <x-text-input type="search" wire:model.live.debounce.300ms="search" placeholder="Find an artist or title" aria-label="Find in the collection" class="w-56" />
        <x-select wire:model.live="medium" aria-label="Medium">
            <option value="">All media</option>@foreach($media as $m)<option value="{{ $m }}">{{ ucfirst($m) }}</option>@endforeach
        </x-select>
        <x-select wire:model.live="ripped" aria-label="Ripped">
            <option value="">Ripped or not</option><option value="1">Ripped</option><option value="0">Not ripped yet</option>
        </x-select>
        <span class="ml-auto text-xs text-gray-400">{{ number_format($items->count()) }} of {{ number_format($total) }}</span>
    </div>

    @if($items->isEmpty())
        <x-empty-state icon="compact-disc" :title="$total === 0 ? 'Your collection is empty' : 'Nothing matches'" :text="$total === 0 ? 'Add a CD, record or tape above, by barcode or by hand.' : 'Change the filters to see more.'" />
    @else
        <x-card flush>
            <div class="divide-y divide-gray-100 dark:divide-stone-800/70" data-test="items">
                @foreach($items as $item)
                    <div class="flex items-center gap-3 px-4 py-3 sm:px-5" data-test="item-{{ $item->id }}">
                        <div class="flex h-10 w-10 flex-none items-center justify-center rounded-xl bg-gray-100 text-gray-400 dark:bg-stone-800 dark:text-stone-500">
                            <i class="fa-solid {{ match($item->medium) { 'vinyl' => 'fa-record-vinyl', 'tape' => 'fa-cassette-tape', default => 'fa-compact-disc' } }}"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                                @if($item->album_id)<a href="{{ route('albums.show', $item->album_id) }}" class="hover:underline">{{ $item->title }}</a>@else{{ $item->title }}@endif
                            </p>
                            <p class="truncate text-xs text-gray-400 dark:text-gray-600">{{ $item->artist }}{{ $item->year ? ' · '.$item->year : '' }} · {{ ucfirst($item->medium) }}{{ $item->location ? ' · '.$item->location : '' }}</p>
                        </div>
                        @if($item->ripped_at)
                            @if($item->album_id)<a href="{{ route('albums.show', $item->album_id) }}" class="flex-none" data-test="ripped-link" title="Open the ripped album"><x-badge variant="success"><i class="fa-solid fa-compact-disc"></i>Ripped</x-badge></a>
                            @else<x-badge variant="success">Ripped</x-badge>@endif
                        @endif
                        <button type="button" wire:click="toggleRipped({{ $item->id }})" class="flex-none text-xs text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">{{ $item->ripped_at ? 'Not ripped' : 'Mark ripped' }}</button>
                        <button type="button" wire:click="remove({{ $item->id }})" wire:confirm="Remove {{ $item->title }} from the collection?" class="flex-none text-xs text-gray-400 underline hover:text-red-600">Remove</button>
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif
</div>
