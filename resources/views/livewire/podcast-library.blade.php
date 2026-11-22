@php
    $clock = fn (?int $s) => $s === null ? null : ($s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60));
@endphp
<div class="space-y-8" data-test="podcast-library">
    @if($notice)
        <x-alert :type="$noticeIsError ? 'error' : 'success'">{{ $notice }}</x-alert>
    @endif

    {{-- Subscriptions --}}
    <section>
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-600">Subscriptions</h2>

        @if($podcasts === [])
            <x-empty-state icon="podcast" title="No podcasts yet" text="Add the RSS address of a show to follow it." />
        @else
            <div class="grid gap-x-4 gap-y-1 sm:grid-cols-2 xl:grid-cols-3" data-test="podcast-rows">
                @foreach($podcasts as $p)
                    <div class="group flex items-center gap-3 rounded-2xl px-2 py-2 hover:bg-gray-100 dark:hover:bg-stone-900 {{ $current && $current['id'] === $p['id'] ? 'bg-gray-100 dark:bg-stone-900' : '' }}" wire:key="podcast-{{ $p['id'] }}" data-test="podcast-{{ $p['id'] }}">
                        <button type="button" wire:click="select({{ $current && $current['id'] === $p['id'] ? 'null' : $p['id'] }})" class="flex min-w-0 flex-1 items-center gap-3 text-left" aria-label="Episodes of {{ $p['title'] }}">
                            <x-artwork-thumb :src="$p['artwork']['proxy_120'] ?? null" :colors="$p['artwork']['colors'] ?? null" :seed="$p['title']" icon="fa-solid fa-podcast" size="h-12 w-12" proxy="120" class="shrink-0" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $p['title'] }}</span>
                                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $p['author'] ?? trans_choice(':count episode|:count episodes', $p['episode_count']) }}</span>
                                @if($p['last_error'])<span class="block truncate text-xs text-amber-600 dark:text-amber-400">{{ $p['last_error'] }}</span>@endif
                            </span>
                            @if($p['unplayed_count'] > 0)<x-badge>{{ $p['unplayed_count'] }} new</x-badge>@endif
                        </button>
                        @if($isAdmin)
                            <button type="button" wire:click="refresh({{ $p['id'] }})" wire:loading.attr="disabled" aria-label="Check {{ $p['title'] }} for new episodes" title="Check for new episodes"
                                    class="flex h-9 w-9 items-center justify-center text-gray-400 transition hover:text-gray-700 dark:text-gray-500 dark:hover:text-gray-300 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100"><i class="fa-solid fa-rotate text-sm"></i></button>
                            <button type="button" wire:click="unsubscribe({{ $p['id'] }})" wire:confirm="Remove this podcast and its episodes?" aria-label="Remove {{ $p['title'] }}" title="Remove"
                                    class="flex h-9 w-9 items-center justify-center text-gray-400 transition hover:text-red-500 dark:text-gray-500 dark:hover:text-red-400 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100"><i class="fa-solid fa-trash-can text-sm"></i></button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if($isAdmin)
            <form wire:submit="subscribe" class="mt-4 flex max-w-2xl flex-wrap items-start gap-2">
                <div class="min-w-0 flex-1">
                    <x-text-input wire:model="feedUrl" type="url" placeholder="https://example.com/feed.xml" aria-label="RSS address of the podcast" class="w-full" required />
                    <x-input-error :messages="$errors->get('feedUrl')" class="mt-1" />
                </div>
                <x-primary-button size="md" type="submit" wire:loading.attr="disabled" wire:target="subscribe"><i class="fa-solid fa-plus text-xs"></i> Add podcast</x-primary-button>
            </form>
        @else
            <p class="mt-4 text-xs text-gray-400 dark:text-gray-600"><a href="{{ route('login') }}" class="underline">Sign in</a> to add or remove podcasts.</p>
        @endif
    </section>

    {{-- Episodes: one podcast, or the new ones of all --}}
    @if($podcasts !== [])
        <section>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $current ? $current['title'] : 'New episodes' }}</h2>
                @if($current)
                    <div class="flex items-center gap-2">
                        <span class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400"><x-toggle :on="$unplayed" label="Only unplayed" wire:click="$toggle('unplayed')" /> Only unplayed</span>
                        <x-secondary-button size="sm" type="button" wire:click="markAllPlayed({{ $current['id'] }})">Mark all played</x-secondary-button>
                    </div>
                @endif
            </div>
            @if($current && $current['description'])
                <p class="mb-3 max-w-3xl text-sm text-gray-500 dark:text-gray-400 line-clamp-3">{{ $current['description'] }}</p>
            @endif

            @if($episodes === [])
                <p class="text-sm text-gray-500" data-test="no-episodes">{{ $current ? 'No episodes to show.' : 'Nothing new. Everything is played.' }}</p>
            @else
                <div class="grid gap-x-4 gap-y-1 lg:grid-cols-2" data-test="episode-rows">
                    @foreach($episodes as $e)
                        <div class="group flex items-center gap-3 rounded-2xl px-2 py-2 hover:bg-gray-100 dark:hover:bg-stone-900" wire:key="episode-{{ $e['id'] }}" data-test="episode-{{ $e['id'] }}">
                            <x-artwork-thumb :src="$e['artwork']['proxy_120'] ?? null" :colors="$e['artwork']['colors'] ?? null" :seed="$e['podcast_title'] ?? $e['title']" icon="fa-solid fa-podcast" size="h-11 w-11" proxy="120" class="shrink-0" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm {{ $e['played'] ? 'text-gray-500 dark:text-gray-500' : 'font-medium text-gray-900 dark:text-gray-100' }}" title="{{ $e['title'] }}">{{ $e['title'] }}</p>
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                    @unless($current){{ $e['podcast_title'] }} · @endunless{{ $e['published_at'] ? \Illuminate\Support\Carbon::parse($e['published_at'])->isoFormat('D MMM YYYY') : '' }}@if($e['duration']) · {{ $clock($e['duration']) }}@endif
                                </p>
                                @if($e['position'])
                                    <p class="mt-0.5 text-xs tabular-nums text-gray-400 dark:text-gray-600">Stopped at {{ $clock($e['position']) }}</p>
                                @endif
                            </div>
                            <button type="button" wire:click="togglePlayed({{ $e['id'] }})" aria-label="{{ $e['played'] ? 'Mark '.$e['title'].' unplayed' : 'Mark '.$e['title'].' played' }}" title="{{ $e['played'] ? 'Played: mark unplayed' : 'Mark played' }}"
                                    class="flex h-9 w-9 items-center justify-center text-gray-400 transition hover:text-gray-700 dark:text-gray-500 dark:hover:text-gray-300 {{ $e['played'] ? '' : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100' }}"><i class="fa-solid fa-check text-sm"></i></button>
                            <button type="button" wire:click="play({{ $e['id'] }})" aria-label="{{ $e['position'] ? 'Resume' : 'Play' }} {{ $e['title'] }}" title="{{ $e['position'] ? 'Resume' : 'Play' }} on the pinned player"
                                    class="flex h-9 w-9 items-center justify-center rounded-full text-gray-900 transition hover:bg-gray-200 dark:text-gray-100 dark:hover:bg-stone-800"><i class="fa-solid fa-play text-sm"></i></button>
                        </div>
                    @endforeach
                </div>
                @if($hasMore)
                    <div class="mt-4"><x-secondary-button size="md" type="button" wire:click="more">Show more</x-secondary-button></div>
                @endif
            @endif
        </section>
    @endif
</div>
