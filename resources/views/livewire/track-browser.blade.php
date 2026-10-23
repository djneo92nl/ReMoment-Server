<div>
    <div class="mb-6 flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-[12rem] max-w-sm">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
            <x-text-input type="search" wire:model.live.debounce.400ms="search" placeholder="Search tracks, albums or artists" class="w-full pl-8" />
        </div>
        <x-select wire:model.live="sort">
            @foreach($sorts as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </x-select>
        <label class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 px-2">
            <input type="checkbox" wire:model.live="fav" class="rounded text-gray-900 dark:text-stone-500 focus:ring-gray-400">
            Favorites
        </label>
        <x-secondary-button size="md" wire:click="$toggle('showFilters')" :aria-expanded="$showFilters ? 'true' : 'false'">
            <i class="fa-solid fa-sliders text-xs"></i>Filters
            @if($normalized['rules'] !== [])
                <span class="rounded-full bg-gray-900 px-1.5 text-xs text-white dark:bg-stone-600">{{ count($normalized['rules']) }}</span>
            @endif
        </x-secondary-button>

        @if($tracks->total() > 0)
            @if($pinned)
                <form method="POST" action="{{ route('library.tracks.shuffle') }}" class="ml-auto">
                    @csrf
                    <input type="hidden" name="scope" value="{{ $scope }}">
                    <input type="hidden" name="q" value="{{ $search }}">
                    <input type="hidden" name="fav" value="{{ $fav ? 1 : 0 }}">
                    <input type="hidden" name="match" value="{{ $match }}">
                    @foreach($normalized['rules'] as $i => $rule)
                        <input type="hidden" name="rules[{{ $i }}][field]" value="{{ $rule['field'] }}">
                        <input type="hidden" name="rules[{{ $i }}][operator]" value="{{ $rule['operator'] }}">
                        <input type="hidden" name="rules[{{ $i }}][value]" value="{{ $rule['value'] }}">
                    @endforeach
                    <x-primary-button size="md" title="Shuffle these tracks on {{ $pinned->device_name }}"><i class="fa-solid fa-shuffle"></i>Shuffle {{ $normalized['rules'] !== [] || $search !== '' || $fav ? 'these' : 'all' }}</x-primary-button>
                </form>
            @else
                <x-primary-button size="md" type="button" class="ml-auto" @click="$dispatch('open-player-panel')" title="Choose a player to shuffle on"><i class="fa-solid fa-shuffle"></i>Shuffle all</x-primary-button>
            @endif
        @endif
    </div>

    @if($showFilters)
        <x-card class="mb-6 space-y-5 p-6">
            <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                <span>Show tracks that match</span>
                <x-select size="sm" wire:model.live="match">
                    <option value="all">all</option>
                    <option value="any">any</option>
                </x-select>
                <span>of these rules</span>
            </div>

            <div class="space-y-2">
                @forelse($rules as $i => $rule)
                    @php($def = $fields[$rule['field']] ?? null)
                    <div wire:key="rule-{{ $i }}" class="flex flex-wrap items-center gap-2">
                        <x-select size="sm" wire:model.live="rules.{{ $i }}.field">
                            @foreach($fields as $key => $field)
                                <option value="{{ $key }}">{{ $field['label'] }}</option>
                            @endforeach
                        </x-select>

                        @if($def)
                            <x-select size="sm" wire:model.live="rules.{{ $i }}.operator">
                                @foreach($def['operators'] as $op => $label)
                                    <option value="{{ $op }}">{{ $label }}</option>
                                @endforeach
                            </x-select>

                            @if($def['type'] === 'select')
                                <x-select size="sm" wire:model.live="rules.{{ $i }}.value" class="min-w-40">
                                    <option value="">Choose&hellip;</option>
                                    @foreach($def['options'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-select>
                            @elseif($def['type'] === 'number')
                                <x-text-input size="sm" type="number" :min="($def['signed'] ?? false) ? null : 0" wire:model.live.debounce.500ms="rules.{{ $i }}.value" class="w-24" />
                                @if($def['unit'])
                                    <span class="text-sm text-gray-400 dark:text-gray-600">{{ $def['unit'] }}</span>
                                @endif
                            @elseif($def['type'] === 'text')
                                <x-text-input size="sm" type="text" maxlength="100" wire:model.live.debounce.500ms="rules.{{ $i }}.value" />
                            @endif
                        @endif

                        <button type="button" wire:click="removeRule({{ $i }})" title="Remove rule"
                                class="w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-red-500 hover:bg-gray-100 dark:hover:bg-stone-700">
                            <i class="fa-solid fa-xmark text-xs"></i>
                        </button>
                    </div>
                @empty
                    <p class="text-sm text-gray-400 dark:text-gray-600">No rules yet: every track matches.</p>
                @endforelse

                <div class="flex items-center gap-4">
                    @if(count($rules) < $maxRules)
                        <x-secondary-button size="sm" wire:click="addRule"><i class="fa-solid fa-plus"></i>Add rule</x-secondary-button>
                    @endif
                    @if($rules !== [])
                        <button type="button" wire:click="clearRules" class="text-xs text-gray-500 hover:text-gray-900 dark:hover:text-gray-100">Clear rules</button>
                    @endif
                </div>

                @if($incomplete > 0)
                    <p class="text-xs text-amber-600 dark:text-amber-500">{{ $incomplete }} {{ Str::plural('rule', $incomplete) }} without a value {{ $incomplete === 1 ? 'is' : 'are' }} ignored.</p>
                @endif
            </div>

            @if($normalized['rules'] !== [])
                <form wire:submit.prevent="saveAsPlaylist" class="border-t border-gray-100 pt-4 dark:border-stone-800">
                    <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Save these rules as a smart playlist</p>
                    <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <x-text-input size="sm" type="text" wire:model="saveName" placeholder="Playlist name" maxlength="255" class="w-56" required />
                        <span>sorted by</span>
                        <x-select size="sm" wire:model="saveSort">
                            @foreach($saveSorts as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </x-select>
                        <span>keep at most</span>
                        <x-text-input size="sm" type="number" min="1" max="{{ $maxLimit }}" wire:model="saveLimit" class="w-24" />
                        <span>tracks</span>
                        <x-primary-button size="md">Save playlist</x-primary-button>
                    </div>
                    <x-input-error :messages="$errors->get('saveName')" class="mt-1" />
                    <x-input-error :messages="$errors->get('saveLimit')" class="mt-1" />
                    @if($search !== '' || $fav)
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-600">The search text and the Favorites switch are not part of a smart playlist.</p>
                    @endif
                </form>
            @endif
        </x-card>
    @endif

    <p class="mb-3 text-sm text-gray-500">{{ number_format($tracks->total()) }} {{ Str::plural('track', $tracks->total()) }} @if($search !== '') matching “{{ $search }}”@endif</p>

    @if($tracks->isEmpty())
        <x-empty-state icon="music" :text="'No tracks here'.(($search !== '' || $fav || $normalized['rules'] !== []) ? ' for these filters' : ' yet').'.'" />
    @else
        <x-card flush class="divide-y divide-gray-100 dark:divide-stone-800">
            @foreach($tracks as $track)
                <div wire:key="track-{{ $track->id }}" class="flex items-center gap-3 px-4 py-2.5">
                    @if($pinned)
                        <form method="POST" action="{{ route('tracks.play', [$track, $pinned]) }}" class="flex-none">
                            @csrf
                            <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-stone-800 dark:hover:text-gray-100" title="Play on {{ $pinned->device_name }}"><i class="fa-solid fa-play text-xs"></i></button>
                        </form>
                    @else
                        <button type="button" @click="$dispatch('open-player-panel')" class="flex h-8 w-8 flex-none items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-stone-800 dark:hover:text-gray-100" title="Choose a player to play on"><i class="fa-solid fa-play text-xs"></i></button>
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm text-gray-900 dark:text-gray-100">{{ $track->name }}</p>
                        <p class="truncate text-xs text-gray-500">
                            @if($track->artist)<a href="{{ route('artists.show', $track->artist) }}" class="hover:underline">{{ $track->artist->name }}</a>@endif
                            @if($track->album) · <a href="{{ route('albums.show', $track->album) }}#track-{{ $track->id }}" class="hover:underline">{{ $track->album->name }}</a>@endif
                        </p>
                    </div>
                    @if($track->duration)
                        <span class="flex-none text-xs tabular-nums text-gray-400 dark:text-stone-500">{{ \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $track->duration) }}</span>
                    @endif
                    <form method="POST" action="{{ route('tracks.favorite', $track) }}" class="flex-none">
                        @csrf
                        <button type="submit" class="{{ $track->favorited_at ? 'text-rose-500 hover:text-rose-600' : 'text-gray-300 hover:text-rose-500 dark:text-stone-600' }}" title="{{ $track->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}"><i class="fa-{{ $track->favorited_at ? 'solid' : 'regular' }} fa-heart"></i></button>
                    </form>
                </div>
            @endforeach
        </x-card>

        @if($tracks->hasPages())
            <div class="mt-8 flex justify-center">{{ $tracks->links() }}</div>
        @endif
    @endif
</div>
