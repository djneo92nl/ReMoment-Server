@php
    $input = 'text-sm rounded-xl border-gray-200 dark:border-stone-700 dark:bg-stone-800 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500';
@endphp

<div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">

    <div class="px-6 py-4 border-b border-gray-100 dark:border-stone-800">
        <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Rules</h2>
    </div>

    <form wire:submit.prevent="save" class="p-6 space-y-5">
        <div>
            <label for="smart-name" class="block text-xs text-gray-400 dark:text-gray-600 mb-1">Name</label>
            <input id="smart-name" type="text" wire:model="name" class="w-full {{ $input }}" required>
            @error('name') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
            <span>Include tracks that match</span>
            <select wire:model.live="match" class="py-1.5 {{ $input }}">
                <option value="all">all</option>
                <option value="any">any</option>
            </select>
            <span>of these rules</span>
        </div>

        <div class="space-y-2">
            @forelse($rules as $i => $rule)
                @php($def = $fields[$rule['field']] ?? null)
                <div wire:key="rule-{{ $i }}" class="flex flex-wrap items-center gap-2">
                    <select wire:model.live="rules.{{ $i }}.field" class="py-1.5 {{ $input }}">
                        @foreach($fields as $key => $field)
                            <option value="{{ $key }}">{{ $field['label'] }}</option>
                        @endforeach
                    </select>

                    @if($def)
                        <select wire:model.live="rules.{{ $i }}.operator" class="py-1.5 {{ $input }}">
                            @foreach($def['operators'] as $op => $label)
                                <option value="{{ $op }}">{{ $label }}</option>
                            @endforeach
                        </select>

                        @if($def['type'] === 'select')
                            <select wire:model.live="rules.{{ $i }}.value" class="py-1.5 min-w-40 {{ $input }}">
                                <option value="">Choose&hellip;</option>
                                @foreach($def['options'] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @elseif($def['type'] === 'number')
                            <input type="number" min="0" wire:model.live.debounce.500ms="rules.{{ $i }}.value" class="py-1.5 w-24 {{ $input }}">
                            @if($def['unit'])
                                <span class="text-sm text-gray-400 dark:text-gray-600">{{ $def['unit'] }}</span>
                            @endif
                        @elseif($def['type'] === 'text')
                            <input type="text" maxlength="100" wire:model.live.debounce.500ms="rules.{{ $i }}.value" class="py-1.5 {{ $input }}">
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
                <button type="button" wire:click="addRule" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
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
            <select wire:model.live="sort" class="py-1.5 {{ $input }}">
                @foreach($sorts as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <span>and keep at most</span>
            <input type="number" min="1" max="{{ \App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder::MAX_LIMIT }}" wire:model.live.debounce.500ms="limit" class="py-1.5 w-24 {{ $input }}">
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
            <button type="submit"
                    class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-medium transition-colors">
                Save &amp; refresh
            </button>
        </div>
    </form>
</div>
