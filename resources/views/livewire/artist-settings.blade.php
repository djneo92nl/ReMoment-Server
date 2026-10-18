@php
    $muted = 'text-sm text-gray-500';
    $thumb = fn (string $url) => \App\Domain\Artwork\LibraryItemArtwork::proxy($url, 320);
    $pending = collect($candidates)->contains(fn ($c) => $thumb($c['url']) === null);
@endphp

<div class="space-y-6" @if($pending) wire:poll.3s @endif>
    {{-- Photo & backdrop --}}
    <x-card tag="section" class="p-8" title="Photo">
        @include('livewire.partials.settings-messages', ['section' => 'photo', 'problems' => $problems, 'notices' => $notices])
        <p class="{{ $muted }} mb-5">The round picture on the artist page, the library and the shelves. {{ $photoIsManual ? 'You picked this one.' : 'It is chosen automatically.' }}
            @if($photoIsManual)<button type="button" wire:click="resetPhoto" class="underline hover:text-gray-700 dark:hover:text-gray-300">Go back to automatic</button>@endif</p>

        @if($candidates === [])
            <p class="{{ $muted }}">No pictures found yet. Add one below.</p>
        @else
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach($candidates as $candidate)
                    @php $selected = $photo === $candidate['url']; @endphp
                    <div wire:key="candidate-{{ md5($candidate['url']) }}" class="group relative">
                        <button type="button" wire:click="choosePhoto(@js($candidate['url']))" aria-pressed="{{ $selected ? 'true' : 'false' }}"
                                class="block w-full overflow-hidden rounded-2xl text-left ring-2 transition {{ $selected ? 'ring-gray-900 dark:ring-white' : 'ring-transparent hover:ring-gray-300 dark:hover:ring-stone-600' }}">
                            <x-artwork-thumb :src="$thumb($candidate['url'])" :seed="$candidate['url']" icon="fa-solid fa-image" size="w-full aspect-square" rounded="rounded-2xl" class="text-3xl" />
                        </button>
                        <p class="mt-1.5 flex items-center gap-1.5 truncate text-xs text-gray-500">
                            @if($selected)<i class="fa-solid fa-circle-check text-gray-900 dark:text-white"></i>@endif{{ $candidate['label'] }}
                        </p>
                        @if($candidate['removable'])
                            <button type="button" wire:click="removeImage(@js($candidate['url']))" aria-label="Remove this picture" title="Remove"
                                    class="absolute right-2 top-2 flex h-8 w-8 items-center justify-center rounded-full bg-black/60 text-white opacity-0 transition hover:bg-black/80 focus:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100"><i class="fa-solid fa-xmark text-xs"></i></button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mt-6 grid gap-4 border-t border-gray-100 pt-6 sm:grid-cols-2 dark:border-stone-800">
            <form wire:submit="addImage" class="flex gap-2">
                <x-text-input type="url" wire:model="imageUrl" placeholder="https://… picture link" aria-label="Picture link" class="min-w-0 flex-1" />
                <x-secondary-button type="submit" wire:loading.attr="disabled" wire:target="addImage">Add</x-secondary-button>
            </form>
            <label class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 px-4 py-2.5 text-sm text-gray-500 transition hover:border-gray-400 hover:text-gray-700 dark:border-stone-700 dark:hover:border-stone-500 dark:hover:text-gray-300">
                <i class="fa-solid fa-arrow-up-from-bracket text-xs"></i>
                <span wire:loading.remove wire:target="upload">Upload a picture</span>
                <span wire:loading wire:target="upload">Uploading…</span>
                <input type="file" wire:model="upload" accept="image/jpeg,image/png,image/gif,image/webp" class="sr-only">
            </label>
        </div>
        @error('upload')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </x-card>

    {{-- Backdrop pictures --}}
    <x-card tag="section" class="p-8" title="Backdrop">
        <p class="{{ $muted }} mb-5">Wide pictures behind the top of the artist page: one at random each visit, plus the collage on big screens. Switch off the ones you don't want to see.</p>

        @if($wide === [])
            <p class="{{ $muted }}">There are no wide pictures for this artist.</p>
        @else
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                @foreach($wide as $url)
                    @php $isHidden = in_array($url, $hidden, true); @endphp
                    <div wire:key="wide-{{ md5($url) }}">
                        <button type="button" wire:click="toggleHidden(@js($url))" aria-pressed="{{ $isHidden ? 'false' : 'true' }}" title="{{ $isHidden ? 'Show on the artist page' : 'Hide from the artist page' }}"
                                class="relative block w-full overflow-hidden rounded-2xl">
                            <x-artwork-thumb :src="$thumb($url)" :seed="$url" icon="fa-solid fa-image" size="w-full aspect-video" rounded="rounded-2xl" class="text-3xl {{ $isHidden ? 'opacity-30 grayscale' : '' }}" />
                            <span class="absolute right-2 top-2 flex h-7 w-7 items-center justify-center rounded-full bg-black/60 text-xs text-white"><i class="fa-solid {{ $isHidden ? 'fa-eye-slash' : 'fa-eye' }}"></i></span>
                        </button>
                    </div>
                @endforeach
            </div>
            <div class="mt-5 flex gap-4 text-sm">
                <button type="button" wire:click="setAllHidden(true)" class="text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">Hide all</button>
                <button type="button" wire:click="setAllHidden(false)" class="text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">Show all</button>
            </div>
        @endif
    </x-card>

    {{-- Identity: which external artist this is --}}
    <x-card tag="section" class="p-8" title="Identity">
        @include('livewire.partials.settings-messages', ['section' => 'identity', 'problems' => $problems, 'notices' => $notices])
        <p class="{{ $muted }} mb-5">Details come from the services below. If one picked another artist with the same name, point it at the right one and everything is fetched again from there.</p>

        <div class="divide-y divide-gray-100 dark:divide-stone-800">
            {{-- MusicBrainz --}}
            <div class="py-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-200">MusicBrainz <span class="font-normal text-gray-400">· also feeds TheAudioDB and Wikipedia</span></p>
                        @if($mappings['mbid']['value'])
                            <p class="mt-0.5 truncate text-xs text-gray-500"><a href="https://musicbrainz.org/artist/{{ $mappings['mbid']['value'] }}" target="_blank" rel="noopener" class="underline">{{ $mappings['mbid']['value'] }}</a>{{ $mappings['mbid']['manual'] ? ' · set by you' : ' · automatic' }}</p>
                        @else
                            <p class="mt-0.5 text-xs text-gray-500">No match yet.</p>
                        @endif
                    </div>
                    @if($mappings['mbid']['manual'])
                        <button type="button" wire:click="resetMapping('mbid')" wire:confirm="Go back to the automatic match? What this one brought is dropped." class="shrink-0 text-xs text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">Reset</button>
                    @endif
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-text-input type="text" wire:model="mbidInput" wire:keydown.enter="chooseMusicBrainz($wire.mbidInput)" placeholder="MusicBrainz id or artist link" aria-label="MusicBrainz id" class="min-w-0 flex-1" />
                    <x-secondary-button type="button" wire:click="chooseMusicBrainz($wire.mbidInput)">Use</x-secondary-button>
                    <x-secondary-button type="button" wire:click="searchMusicBrainz" wire:loading.attr="disabled" wire:target="searchMusicBrainz">Search "{{ $artist->name }}"</x-secondary-button>
                </div>
                @if($mbCandidates !== null)
                    <ul class="mt-3 space-y-1">
                        @forelse($mbCandidates as $candidate)
                            <li wire:key="mb-{{ $candidate['id'] }}" class="flex items-center justify-between gap-3 rounded-xl px-3 py-2 hover:bg-gray-50 dark:hover:bg-stone-800/50">
                                <div class="min-w-0"><p class="truncate text-sm text-gray-800 dark:text-gray-200">{{ $candidate['name'] }}</p><p class="truncate text-xs text-gray-500">{{ $candidate['detail'] ?: 'No more details' }}</p></div>
                                <button type="button" wire:click="chooseMusicBrainz(@js($candidate['id']))" class="shrink-0 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium hover:bg-gray-200 dark:bg-stone-800 dark:hover:bg-stone-700">Use</button>
                            </li>
                        @empty
                            <li class="{{ $muted }}">MusicBrainz found no artist with that name.</li>
                        @endforelse
                    </ul>
                @endif
            </div>

            {{-- Spotify --}}
            <div class="py-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Spotify <span class="font-normal text-gray-400">· photo and albums</span></p>
                        @if($mappings['spotify_id']['value'])
                            <p class="mt-0.5 truncate text-xs text-gray-500"><a href="https://open.spotify.com/artist/{{ $mappings['spotify_id']['value'] }}" target="_blank" rel="noopener" class="underline">{{ $mappings['spotify_id']['value'] }}</a>{{ $mappings['spotify_id']['manual'] ? ' · set by you' : ' · automatic' }}</p>
                        @else
                            <p class="mt-0.5 text-xs text-gray-500">No match yet.</p>
                        @endif
                    </div>
                    @if($mappings['spotify_id']['manual'])
                        <button type="button" wire:click="resetMapping('spotify_id')" class="shrink-0 text-xs text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">Reset</button>
                    @endif
                </div>
                @if($spotifyConnected)
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-text-input type="text" wire:model="spotifyInput" wire:keydown.enter="chooseSpotify($wire.spotifyInput)" placeholder="Spotify artist link or id" aria-label="Spotify artist" class="min-w-0 flex-1" />
                        <x-secondary-button type="button" wire:click="chooseSpotify($wire.spotifyInput)">Use</x-secondary-button>
                        <x-secondary-button type="button" wire:click="searchSpotify" wire:loading.attr="disabled" wire:target="searchSpotify">Search "{{ $artist->name }}"</x-secondary-button>
                    </div>
                    @if($spotifyCandidates !== null)
                        <ul class="mt-3 space-y-1">
                            @forelse($spotifyCandidates as $candidate)
                                <li wire:key="sp-{{ $candidate['id'] }}" class="flex items-center justify-between gap-3 rounded-xl px-3 py-2 hover:bg-gray-50 dark:hover:bg-stone-800/50">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <x-artwork-thumb :src="$candidate['image']" :seed="$candidate['name']" icon="fa-solid fa-microphone-lines" size="h-10 w-10" rounded="rounded-full" />
                                        <p class="truncate text-sm text-gray-800 dark:text-gray-200">{{ $candidate['name'] }}</p>
                                    </div>
                                    <button type="button" wire:click="chooseSpotify(@js($candidate['id']))" class="shrink-0 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium hover:bg-gray-200 dark:bg-stone-800 dark:hover:bg-stone-700">Use</button>
                                </li>
                            @empty
                                <li class="{{ $muted }}">Spotify found no artist with that name.</li>
                            @endforelse
                        </ul>
                    @endif
                @else
                    <p class="mt-2 text-xs text-gray-500">Connect Spotify in the settings to change this.</p>
                @endif
            </div>

            {{-- Last.fm --}}
            <div class="py-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Last.fm <span class="font-normal text-gray-400">· bio, listeners, similar artists</span></p>
                        <p class="mt-0.5 truncate text-xs text-gray-500">Looked up as "{{ $mappings['lastfm_name']['value'] }}"{{ $mappings['lastfm_name']['manual'] ? ' · set by you' : '' }}</p>
                    </div>
                    @if($mappings['lastfm_name']['manual'])
                        <button type="button" wire:click="resetMapping('lastfm_name')" class="shrink-0 text-xs text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">Reset</button>
                    @endif
                </div>
                <form wire:submit="saveLastfmName" class="mt-3 flex gap-2">
                    <x-text-input type="text" wire:model="lastfmInput" placeholder="The artist's name on Last.fm" aria-label="Name on Last.fm" class="min-w-0 flex-1" />
                    <x-secondary-button type="submit">Use</x-secondary-button>
                </form>
            </div>
        </div>

        <div class="mt-2 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-5 dark:border-stone-800">
            <span class="mr-1 text-sm text-gray-500">Fetch again:</span>
            <x-secondary-button type="button" wire:click="refetch" wire:loading.attr="disabled" wire:target="refetch">Everything</x-secondary-button>
            @foreach(\App\Domain\Library\ArtistIdentity::SOURCES as $source => $label)
                <x-secondary-button type="button" wire:click="refetch(@js($source))" wire:loading.attr="disabled" wire:target="refetch">{{ $label }}</x-secondary-button>
            @endforeach
        </div>
    </x-card>

    {{-- Name and duplicates --}}
    <x-card tag="section" class="p-8" title="Name and duplicates">
        @include('livewire.partials.settings-messages', ['section' => 'names', 'problems' => $problems, 'notices' => $notices])
        <form wire:submit="rename" class="flex gap-2">
            <x-text-input type="text" wire:model="nameInput" maxlength="255" aria-label="Artist name" class="min-w-0 flex-1" />
            <x-secondary-button type="submit">Rename</x-secondary-button>
        </form>

        <div class="mt-6 border-t border-gray-100 pt-6 dark:border-stone-800">
            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">Merge another artist into this one</p>
            <p class="{{ $muted }} mt-1">For a duplicate, such as a spelling variant. Their albums, tracks and plays move here and the other artist is removed.</p>
            <x-text-input type="search" wire:model.live.debounce.400ms="mergeSearch" placeholder="Search the library" aria-label="Search artists to merge" class="mt-3 w-full" />
            @if(trim($mergeSearch) !== '')
                <ul class="mt-3 space-y-1">
                    @forelse($mergeCandidates as $other)
                        <li wire:key="merge-{{ $other['id'] }}" class="flex items-center justify-between gap-3 rounded-xl px-3 py-2 hover:bg-gray-50 dark:hover:bg-stone-800/50">
                            <div class="min-w-0"><p class="truncate text-sm text-gray-800 dark:text-gray-200">{{ $other['name'] }}</p><p class="text-xs text-gray-500">{{ $other['albums'] }} {{ Str::plural('album', $other['albums']) }} · {{ $other['tracks'] }} {{ Str::plural('track', $other['tracks']) }}</p></div>
                            <button type="button" wire:click="merge({{ $other['id'] }})" wire:confirm="Merge {{ $other['name'] }} into {{ $artist->name }}? This can't be undone." class="shrink-0 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium hover:bg-gray-200 dark:bg-stone-800 dark:hover:bg-stone-700">Merge</button>
                        </li>
                    @empty
                        <li class="{{ $muted }}">No other artist matches.</li>
                    @endforelse
                </ul>
            @endif
        </div>
    </x-card>

    {{-- Details --}}
    <x-card tag="section" class="p-8" title="Details">
        @include('livewire.partials.settings-messages', ['section' => 'details', 'problems' => $problems, 'notices' => $notices])
        <p class="{{ $muted }} mb-5">What the page shows besides the name. Fill a field to use your own text; leave it empty to use what the sources found (shown as the hint).</p>
        <form wire:submit="saveDetails" class="space-y-4">
            <div>
                <label for="d-bio" class="mb-1 block text-xs font-medium text-gray-500">Biography</label>
                <textarea id="d-bio" wire:model="details.bio" rows="5" placeholder="{{ $automaticDetails['bio'] ? \Illuminate\Support\Str::limit($automaticDetails['bio'], 160) : '' }}"
                          class="w-full rounded-xl border-gray-300 bg-white text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500 dark:border-stone-700 dark:bg-stone-900 dark:text-gray-100"></textarea>
                @error('details.bio')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="d-genres" class="mb-1 block text-xs font-medium text-gray-500">Genres <span class="font-normal">(comma separated)</span></label>
                    <x-text-input id="d-genres" type="text" wire:model="details.genres" placeholder="{{ $automaticDetails['genres'] }}" class="w-full" />
                </div>
                <div>
                    <label for="d-tags" class="mb-1 block text-xs font-medium text-gray-500">Tags <span class="font-normal">(comma separated)</span></label>
                    <x-text-input id="d-tags" type="text" wire:model="details.tags" placeholder="{{ $automaticDetails['tags'] }}" class="w-full" />
                </div>
                <div>
                    <label for="d-country" class="mb-1 block text-xs font-medium text-gray-500">Country</label>
                    <x-text-input id="d-country" type="text" wire:model="details.country" placeholder="{{ $automaticDetails['country'] }}" class="w-full" />
                </div>
                <div>
                    <label for="d-type" class="mb-1 block text-xs font-medium text-gray-500">Type</label>
                    <x-select id="d-type" wire:model="details.type">
                        <option value="">Automatic{{ $automaticDetails['type'] ? ' ('.$automaticDetails['type'].')' : '' }}</option>
                        @foreach(\App\Domain\Library\ArtistOverrides::TYPES as $type)
                            <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                        @endforeach
                    </x-select>
                </div>
            </div>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveDetails">Save</x-primary-button>
        </form>
    </x-card>

    {{-- Library & files --}}
    <x-card tag="section" class="p-8" title="Library and files">
        @include('livewire.partials.settings-messages', ['section' => 'library', 'problems' => $problems, 'notices' => $notices])

        @if($sources === [])
            <p class="{{ $muted }}">This artist has no tracks in any library.</p>
        @else
            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                @foreach($sources as $row)
                    <div class="rounded-2xl bg-gray-50 px-4 py-3 dark:bg-stone-800/50">
                        <dt class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-200"><x-source-icon :source="$row['id']" />{{ $row['name'] }}</dt>
                        <dd class="mt-0.5 text-xs text-gray-500">{{ $row['albums'] }} {{ Str::plural('album', $row['albums']) }} · {{ $row['tracks'] }} {{ Str::plural('track', $row['tracks']) }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        <form wire:submit="saveDefaultSource" class="mt-6 flex flex-wrap items-center gap-3">
            <label for="default-source" class="text-sm text-gray-700 dark:text-gray-300">Open this artist's pages on</label>
            <x-select id="default-source" wire:model="defaultSource" wire:change="saveDefaultSource">
                <option value="">Automatic</option>
                @foreach(\App\Domain\Library\LibrarySources::LIBRARIES as $id => $library)
                    <option value="{{ $id }}">{{ $library['name'] }}</option>
                @endforeach
            </x-select>
        </form>

        <div class="mt-8 border-t border-gray-100 pt-6 dark:border-stone-800">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-medium text-gray-800 dark:text-gray-200">Local files <span class="font-normal text-gray-400">· {{ $fileCount }}</span></h3>
                @if($fileCount > 0)
                    <div class="flex gap-4 text-sm">
                        <button type="button" wire:click="setAllFilesHidden(true)" class="text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">Hide all</button>
                        <button type="button" wire:click="setAllFilesHidden(false)" class="text-gray-500 underline hover:text-gray-800 dark:hover:text-gray-300">Show all</button>
                    </div>
                @endif
            </div>
            @if($fileCount === 0)
                <p class="{{ $muted }} mt-2">No own files. Streaming tracks have nothing stored here.</p>
            @else
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-stone-800">
                    @foreach($files as $file)
                        <li wire:key="file-{{ $file->id }}" class="flex items-center gap-3 py-2.5 {{ $file->isHidden() ? 'opacity-60' : '' }}">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm text-gray-800 dark:text-gray-200" title="{{ $file->path }}">{{ $file->tracks->first()?->name ?? basename($file->path) }}</p>
                                <p class="truncate text-xs text-gray-500">{{ $file->location?->name }}/{{ $file->path }} · {{ \Illuminate\Support\Number::fileSize((int) $file->size) }}
                                    @if($file->status === \App\Models\File::MISSING) · <span class="text-red-600">missing</span>@endif
                                    @if($file->isHidden()) · hidden @endif</p>
                            </div>
                            <x-menu label="File options">
                                <x-menu.item icon="{{ $file->isHidden() ? 'eye' : 'eye-slash' }}" wire:click="toggleFileHidden({{ $file->id }})">{{ $file->isHidden() ? 'Show in library' : 'Hide from library' }}</x-menu.item>
                                <x-menu.item icon="rotate" wire:click="rematchFile({{ $file->id }})">Match again</x-menu.item>
                                <x-menu.item icon="shield-halved" wire:click="verifyFile({{ $file->id }})">Verify</x-menu.item>
                                <x-menu.item icon="trash-can" danger wire:click="deleteFile({{ $file->id }})" wire:confirm="Delete this file from storage? This can't be undone.">Delete file</x-menu.item>
                            </x-menu>
                        </li>
                    @endforeach
                </ul>
                @if($fileCount > $files->count())
                    <p class="{{ $muted }} mt-3">Showing the first {{ $files->count() }}. Use the <a href="{{ route('settings.storage') }}" class="underline">storage browser</a> for the rest.</p>
                @endif
            @endif
        </div>

        @if($removable)
            <div class="mt-8 border-t border-gray-100 pt-6 dark:border-stone-800">
                <button type="button" wire:click="removeFromLibrary" wire:confirm="Remove this artist and all their albums and tracks from the library? Your play history is kept."
                        class="text-sm text-red-600 hover:text-red-700"><i class="fa-solid fa-trash-can mr-1.5"></i>Remove from library</button>
            </div>
        @endif
    </x-card>
</div>
