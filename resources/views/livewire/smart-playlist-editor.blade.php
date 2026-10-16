<x-card flush>

    <div class="px-6 py-4 border-b border-gray-100 dark:border-stone-800">
        <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Rules</h2>
    </div>

    <form wire:submit.prevent="save" class="p-6 space-y-5">
        <div>
            <x-input-label for="smart-name" value="Name" class="mb-1" />
            <x-text-input id="smart-name" type="text" wire:model="name" class="w-full" required />
            <x-input-error :messages="$errors->get('name')" class="mt-1" />
        </div>

        <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
            <span>Include tracks that match</span>
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
                <p class="text-sm text-gray-400 dark:text-gray-600">No rules yet: every track in the library matches.</p>
            @endforelse

            @if(count($rules) < \App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder::MAX_RULES)
                <button type="button" wire:click="addRule" class="text-xs font-medium text-gray-900 dark:text-gray-100 underline">
                    <i class="fa-solid fa-plus mr-1"></i>Add rule
                </button>
            @endif

            @if($incomplete > 0)
                <p class="text-xs text-amber-600 dark:text-amber-500">
                    {{ $incomplete }} {{ Str::plural('rule', $incomplete) }} without a value {{ $incomplete === 1 ? 'is' : 'are' }} ignored.
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
            <span>Sort by</span>
            <x-select size="sm" wire:model.live="sort">
                @foreach($sorts as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </x-select>
            <span>and keep at most</span>
            <x-text-input size="sm" type="number" min="1" max="{{ \App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder::MAX_LIMIT }}" wire:model.live.debounce.500ms="limit" class="w-24" />
            <span>tracks</span>
        </div>

        <div class="rounded-2xl bg-gray-50 dark:bg-stone-800/50 p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                {{ number_format($matches) }} {{ Str::plural('track', $matches) }} match{{ $matches === 1 ? 'es' : '' }}@if($matches > $limit && $limit > 0), the playlist keeps {{ number_format(min($limit, \App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder::MAX_LIMIT)) }}@endif.
                @if($sort === 'random')
                    <span class="text-gray-400 dark:text-gray-600">Random order: the preview is a sample, and the list is reshuffled daily.</span>
                @endif
            </p>
            @if($preview->isNotEmpty())
                <ul class="mt-3 space-y-1">
                    @foreach($preview as $track)
                        <li class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400 truncate">
                            <x-source-icon :source="$track->source" class="flex-shrink-0" />
                            <span class="truncate">{{ $track->name }} <span class="text-gray-400 dark:text-gray-600">&middot; {{ $track->artist?->name }}</span></span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="flex items-center justify-between">
            <p class="text-xs text-gray-400 dark:text-gray-600">
                @if($playlist->refreshed_at)
                    Last refreshed {{ $playlist->refreshed_at->diffForHumans() }}.
                @endif
            </p>
            <x-primary-button>Save &amp; refresh</x-primary-button>
        </div>
    </form>
</x-card>
