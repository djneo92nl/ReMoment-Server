<x-app-layout>
    <x-slot name="header">
        @php
            $albumSources = $album->tracks->flatMap(fn ($t) => $t->sources())->unique();
            $albumRemovable = $album->tracks->isEmpty() || $album->tracks->every(fn ($t) => $t->isRemovable());
        @endphp
        <x-page-header :title="$album->name" :back="route('artists.show', $album->artist)">
            <x-slot name="badge">
                <form method="POST" action="{{ route('albums.favorite', $album) }}">
                    @csrf
                    <button type="submit"
                            title="{{ $album->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}"
                            class="text-xl transition-colors {{ $album->favorited_at ? 'text-rose-500 hover:text-rose-600' : 'text-gray-300 hover:text-rose-400 dark:text-stone-600' }}">
                        <i class="{{ $album->favorited_at ? 'fa-solid' : 'fa-regular' }} fa-heart"></i>
                    </button>
                </form>
            </x-slot>
            <x-slot name="meta">
                <a href="{{ route('artists.show', $album->artist) }}" class="hover:underline">{{ $album->artist->name }}</a>
                @if($album->released_at)
                    &middot; {{ $album->released_at->format('Y') }}
                @endif
                @if($totalPlays > 0)
                    &middot; {{ number_format($totalPlays) }} {{ Str::plural('play', $totalPlays) }}
                @endif
            </x-slot>
            <div class="flex flex-wrap items-center gap-2">
                <x-source-badges :dlna="$albumSources->contains('dlna')" :spotify="$albumSources->contains('spotify')" :labels="true" :light="true" />
                @auth
                    @if($albumRemovable)
                        <form method="POST" action="{{ route('albums.destroy', $album) }}" onsubmit="return confirm('Remove this album and its tracks from the library? Your play history is kept.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-gray-400 hover:text-red-500 transition-colors">
                                <i class="fa-solid fa-trash-can mr-1"></i>Remove from library
                            </button>
                        </form>
                    @endif
                @endauth
            </div>
        </x-page-header>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">

        <!-- Left: Album art + tracklist -->
        <div class="lg:col-span-2 space-y-6">

            <x-card class="overflow-hidden">

                @php
                    $artUrl = $album->images[0]['url'] ?? null;
                    $colors = $album->colors ?? [];
                @endphp

                {{-- Hero --}}
                <div class="flex gap-6 p-6 md:p-8"
                     @if(count($colors) >= 2) style="background: linear-gradient(135deg, {{ $colors[0] }}22, {{ $colors[1] }}11)" @endif>
                    <x-artwork-thumb
                        :src="$artUrl"
                        :colors="$colors"
                        :seed="$album->name.$album->id"
                        icon="fa-solid fa-compact-disc"
                        size="w-32 h-32"
                        rounded="rounded-2xl"
                        class="shadow-lg ring-1 ring-black/5 text-3xl"
                    />
                    <div class="flex-1 min-w-0 pt-2">
                        <p class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-1">Album</p>
                        <h2 class="text-xl font-medium text-gray-900 dark:text-gray-100 leading-snug">{{ $album->name }}</h2>
                        <p class="text-sm text-gray-500 mt-1">{{ $album->artist->name }}</p>
                        @php
                            $details = $album->details();
                            $subline = collect([
                                $details['release_type'] ? ucfirst($details['release_type']) : null,
                                $details['format'],
                                $album->released_at?->year,
                                $details['label'],
                                $details['catalog_number'],
                                $details['track_count'] ? $details['track_count'].' '.Str::plural('track', $details['track_count']) : null,
                            ])->filter()->implode(' · ');
                        @endphp
                        @if($subline)
                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">{{ $subline }}</p>
                        @endif
                        @php $genres = $album->genres() ?: $album->artist->genres() @endphp
                        @if(count($genres))
                            <div class="flex flex-wrap gap-1.5 mt-2">
                                @foreach(array_slice($genres, 0, 5) as $genre)
                                    <x-badge>{{ $genre }}</x-badge>
                                @endforeach
                            </div>
                        @endif
                        @php
                            $albumTags = collect([$details['mood'], $details['theme'], $details['style'], ...$details['tags']])->filter()
                                ->reject(fn ($tag) => collect($genres)->contains(fn ($g) => strcasecmp($g, $tag) === 0))->unique()->take(8);
                        @endphp
                        @if($albumTags->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5 mt-1.5">
                                @foreach($albumTags as $tag)
                                    <x-badge variant="outline">{{ $tag }}</x-badge>
                                @endforeach
                            </div>
                        @endif
                        @if($details['rating'] || $details['listeners'])
                            <p class="mt-2 text-xs text-gray-400 dark:text-gray-600">
                                {{ collect([
                                    $details['rating'] ? '★ '.$details['rating'].' / 10' : null,
                                    $details['listeners'] ? number_format($details['listeners']).' listeners on Last.fm' : null,
                                ])->filter()->implode(' · ') }}
                            </p>
                        @endif
                        @if($details['summary'])
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400 leading-relaxed line-clamp-4">{{ $details['summary'] }}
                                @if($details['wikipedia_url'])
                                    <a href="{{ $details['wikipedia_url'] }}" target="_blank" rel="noopener" class="text-gray-900 dark:text-gray-100 underline">Wikipedia</a>
                                @endif
                            </p>
                        @endif
                        @if(count($colors) > 0)
                            <div class="flex gap-1.5 mt-4">
                                @foreach(array_slice($colors, 0, 5) as $color)
                                    <div class="w-5 h-5 rounded-full shadow-sm ring-1 ring-black/10" style="background: {{ $color }}"></div>
                                @endforeach
                            </div>
                        @endif
                        @if($spotifyConnected)
                            <form method="POST" action="{{ route('albums.fill', $album) }}" class="mt-4" x-data="{ busy: false }" @submit="busy = true">
                                @csrf
                                <button type="submit" :disabled="busy"
                                        class="flex items-center gap-2 px-4 py-2 rounded-2xl bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-700 dark:text-gray-300 text-sm font-medium transition-colors disabled:opacity-60">
                                    <i class="fa-brands fa-spotify text-emerald-500" :class="busy && 'animate-pulse'"></i>
                                    <span x-text="busy ? 'Adding tracks…' : 'Fill out album from Spotify'"></span>
                                </button>
                            </form>
                        @endif
                        @if($playableDevices->isNotEmpty() && $album->tracks->isNotEmpty())
                            <div class="flex items-center gap-2 mt-4">
                                <x-play-button
                                    name="play-album-{{ $album->id }}"
                                    title="Play album"
                                    :description="$album->name"
                                    :devices="$playableDevices"
                                    :action-template="\App\Support\DeviceAction::template('albums.play', ['album' => $album])"
                                    class="flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-gray-900 dark:bg-stone-700 hover:bg-gray-700 dark:hover:bg-stone-600 text-white text-sm font-medium transition-colors">
                                    <i class="fa-solid fa-play text-xs"></i>
                                    Play all
                                </x-play-button>
                                <x-play-button
                                    name="shuffle-album-{{ $album->id }}"
                                    title="Shuffle album"
                                    :description="$album->name"
                                    :devices="$playableDevices"
                                    :action-template="\App\Support\DeviceAction::template('albums.play', ['album' => $album, 'shuffle' => 1])"
                                    :elsewhere="false"
                                    class="flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-700 dark:text-gray-300 text-sm font-medium transition-colors">
                                    <i class="fa-solid fa-shuffle text-xs"></i>
                                    Shuffle
                                </x-play-button>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Tracklist --}}
                @if($album->tracks->isNotEmpty())
                    @php
                        $localCount = $album->tracks->filter(fn ($t) => $t->getDlnaUrl())->count();
                        $addedCount = $album->tracks->count() - $localCount;
                    @endphp
                    <div x-data="{ localOnly: false }">
                    @if($localCount > 0 && $addedCount > 0)
                        <div class="flex items-center justify-between gap-3 px-6 py-2.5 border-t border-gray-100 dark:border-stone-800 text-xs text-gray-500">
                            <span>{{ $addedCount }} {{ Str::plural('track', $addedCount) }} added from Spotify</span>
                            <button type="button" @click="localOnly = !localOnly"
                                    class="flex items-center gap-1.5 px-3 py-1 rounded-full bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-600 dark:text-gray-400 transition-colors">
                                <i class="fa-solid" :class="localOnly ? 'fa-eye' : 'fa-eye-slash'"></i>
                                <span x-text="localOnly ? 'Show all tracks' : 'Only my library'"></span>
                            </button>
                        </div>
                    @endif
                    <div class="border-t border-gray-100 dark:border-stone-800 divide-y divide-gray-50 dark:divide-stone-800/50">
                        @foreach($album->tracks as $i => $track)
                            @php
                                $dlnaUrl = $track->getDlnaUrl();
                                $lyrics = $track->lyricsPlain();
                                $quality = $track->audioQuality();
                                $credits = $track->credits();
                                $trackGenres = $track->genres();
                                $isrc = $track->metaValue('isrc');
                                $popularity = $track->metaValue('spotify_popularity');
                                $listeners = $track->metaInt('lastfm_listeners');
                                $recordingMbid = $track->recordingMbid();
                                $sound = $track->sound();
                                $soundLine = $sound ? collect([
                                    $sound['bpm'] ? round($sound['bpm']).' BPM' : null,
                                    $sound['key'] ? $sound['key'].' '.$sound['mode'] : null,
                                    $sound['loudness'] !== null ? number_format($sound['loudness'], 1).' LUFS' : null,
                                ])->filter()->implode(' · ') : '';
                                $waveform = $track->waveform();
                                $hasInfo = $waveform || $quality || $credits || $trackGenres || $isrc || $popularity !== null || $listeners || $soundLine !== '' || ($sound && $sound['mood']);
                            @endphp
                            <div id="track-{{ $track->id }}" class="scroll-mt-24" x-data="{ panel: null }" @if($localCount > 0 && !$dlnaUrl) x-show="!localOnly" @endif>
                                @php
                                    $trackDevices = $playableDevices->filter(fn ($d) => \App\Domain\Library\LibraryPlayback::trackPlayable($track, $d))->values();
                                    $slot = 'width:28px;height:28px;flex:none';
                                    $btn = 'w-full h-full rounded-full flex items-center justify-center hover:bg-gray-100 dark:hover:bg-stone-700 transition-colors';
                                @endphp
                                <div class="flex items-center gap-3 px-6 py-3 hover:bg-gray-50 dark:hover:bg-stone-800/30 transition-colors group">
                                    {{-- Play, then the track number: always in the same place --}}
                                    <div style="{{ $slot }}">
                                        @if($trackDevices->isNotEmpty())
                                            <x-play-button
                                                name="play-track-{{ $track->id }}"
                                                title="Play track"
                                                :description="$track->name"
                                                :devices="$trackDevices"
                                                :action-template="\App\Support\DeviceAction::template('tracks.play', ['track' => $track])"
                                                :elsewhere="false"
                                                wrapper-class="w-full h-full"
                                                class="{{ $btn }} text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100">
                                                <i class="fa-solid fa-play text-xs"></i>
                                            </x-play-button>
                                        @endif
                                    </div>
                                    <span class="w-5 text-center text-xs text-gray-400 dark:text-stone-500 flex-shrink-0">{{ $track->metaValue('track_number') ?? $i + 1 }}</span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate flex items-center gap-1.5">
                                            {{ $track->name }}
                                            @foreach($track->sources() ?: [$track->source] as $trackSource)
                                                <x-source-icon :source="$trackSource" />
                                            @endforeach
                                            @if($track->metaValue('explicit') === '1')
                                                <span class="px-1 rounded bg-gray-200 dark:bg-stone-700 text-[9px] font-semibold text-gray-500 dark:text-gray-400" title="Explicit">E</span>
                                            @endif
                                        </p>
                                        {{-- Length, and tempo and mood when the file is analysed: visible without opening the options --}}
                                        @php
                                            $rowMeta = collect([
                                                $track->duration ? \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $track->duration) : null,
                                                ($sound['bpm'] ?? null) ? round($sound['bpm']).' BPM' : null,
                                                ($sound['mood'] ?? null) ? implode(', ', array_map('ucfirst', array_slice($sound['mood'], 0, 2))) : null,
                                            ])->filter()->implode(' · ');
                                        @endphp
                                        @if($rowMeta !== '')
                                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">{{ $rowMeta }}</p>
                                        @endif
                                    </div>
                                    {{-- Fixed slots, so the columns line up whatever a track has --}}
                                    <span class="text-xs text-gray-400 dark:text-stone-500 text-right flex-shrink-0" style="width:2.5rem">
                                        @if(isset($track->plays_count) && $track->plays_count > 0){{ number_format($track->plays_count) }}×@endif
                                    </span>
                                    {{-- One options column instead of several optional icon columns, so rows never have gaps. Fixed position so the card's overflow can't clip the menu. --}}
                                    @php
                                        $removable = auth()->check() && $track->isRemovable();
                                        $hasOptions = $trackDevices->isNotEmpty() || $hasInfo || $lyrics || $removable;
                                        $item = 'w-full flex items-center gap-2.5 px-4 py-2 text-left text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-stone-700';
                                    @endphp
                                    <div style="{{ $slot }}"
                                         x-data="{ open: false, top: 0, right: 0 }" @click.outside="open = false" @keydown.escape.window="open = false" @scroll.window="open = false">
                                        @if($hasOptions)
                                            <button type="button" class="{{ $btn }} text-gray-400" title="Options"
                                                    :class="(panel || open) && 'text-blue-500 dark:text-blue-400'"
                                                    @click="const r = $el.getBoundingClientRect(); top = r.bottom + 4; right = window.innerWidth - r.right; open = !open">
                                                <i class="fa-solid fa-ellipsis text-xs"></i>
                                            </button>
                                            <div x-show="open" x-cloak x-transition.opacity
                                                 :style="`position:fixed;top:${top}px;right:${right}px;z-index:45`"
                                                 class="min-w-52 py-1 rounded-xl bg-white dark:bg-stone-800 shadow-xl border border-gray-200 dark:border-stone-700 text-sm">
                                                @if($trackDevices->isNotEmpty())
                                                    <button type="button" class="{{ $item }}"
                                                            @click="open = false; $dispatch('open-modal', 'play-track-{{ $track->id }}')">
                                                        <i class="fa-solid fa-tv text-xs text-gray-400 w-4"></i>Play on device&hellip;
                                                    </button>
                                                @endif
                                                @if($hasInfo)
                                                    <button type="button" class="{{ $item }}" @click="open = false; panel = panel === 'info' ? null : 'info'">
                                                        <i class="fa-solid fa-circle-info text-xs text-gray-400 w-4"></i>Track details
                                                    </button>
                                                @endif
                                                @if($lyrics)
                                                    <button type="button" class="{{ $item }}" @click="open = false; panel = panel === 'lyrics' ? null : 'lyrics'">
                                                        <i class="fa-solid fa-align-left text-xs text-gray-400 w-4"></i>Lyrics
                                                    </button>
                                                @endif
                                                @if($removable)
                                                    <form method="POST" action="{{ route('tracks.destroy', $track) }}" onsubmit="return confirm('Remove this track from the library? Your play history is kept.')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="{{ $item }} hover:text-red-500">
                                                            <i class="fa-solid fa-trash-can text-xs text-gray-400 w-4"></i>Remove from library
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                @if($hasInfo)
                                    <div x-show="panel === 'info'" x-cloak class="px-14 py-4 border-t border-gray-50 dark:border-stone-800/50 bg-gray-50/50 dark:bg-stone-800/20">
                                        <dl class="text-xs" style="display:grid;grid-template-columns:max-content 1fr;gap:6px 24px">
                                            @if($trackGenres)
                                                <dt class="text-gray-400 dark:text-gray-600">Genres</dt>
                                                <dd class="text-gray-700 dark:text-gray-300">{{ implode(', ', $trackGenres) }}</dd>
                                            @endif
                                            @if($waveform)
                                                <dt class="text-gray-400 dark:text-gray-600">Waveform</dt>
                                                <dd class="text-gray-500 dark:text-gray-400" style="max-width:32rem"><x-waveform :points="$waveform" /></dd>
                                            @endif
                                            @if($quality)
                                                <dt class="text-gray-400 dark:text-gray-600">Quality</dt>
                                                <dd class="text-gray-700 dark:text-gray-300">{{ $quality }}</dd>
                                            @endif
                                            @if($soundLine !== '')
                                                <dt class="text-gray-400 dark:text-gray-600">Sound</dt>
                                                <dd class="text-gray-700 dark:text-gray-300">{{ $soundLine }}</dd>
                                            @endif
                                            @if($sound && $sound['danceability'] !== null)
                                                <dt class="text-gray-400 dark:text-gray-600">Danceability</dt>
                                                <dd class="text-gray-700 dark:text-gray-300">{{ round($sound['danceability'] * 100) }}%{{ $sound['instrumental'] !== null ? ' · '.($sound['instrumental'] >= 0.5 ? 'instrumental' : 'vocals') : '' }}</dd>
                                            @endif
                                            @if($sound && $sound['mood'])
                                                <dt class="text-gray-400 dark:text-gray-600">Mood</dt>
                                                <dd class="text-gray-700 dark:text-gray-300">{{ implode(', ', $sound['mood']) }}</dd>
                                            @endif
                                            @foreach($credits as $role => $names)
                                                <dt class="text-gray-400 dark:text-gray-600">{{ ucfirst($role) }}</dt>
                                                <dd class="text-gray-700 dark:text-gray-300">{{ implode(', ', $names) }}</dd>
                                            @endforeach
                                            @if($popularity !== null)
                                                <dt class="text-gray-400 dark:text-gray-600">Spotify popularity</dt>
                                                <dd class="text-gray-700 dark:text-gray-300">{{ $popularity }} / 100</dd>
                                            @endif
                                            @if($listeners)
                                                <dt class="text-gray-400 dark:text-gray-600">Last.fm</dt>
                                                <dd class="text-gray-700 dark:text-gray-300">{{ number_format($listeners) }} listeners · {{ number_format($track->metaInt('lastfm_playcount')) }} plays</dd>
                                            @endif
                                            @if($isrc)
                                                <dt class="text-gray-400 dark:text-gray-600">ISRC</dt>
                                                <dd class="text-gray-700 dark:text-gray-300 font-mono">{{ $isrc }}</dd>
                                            @endif
                                            @if($recordingMbid)
                                                <dt class="text-gray-400 dark:text-gray-600">MusicBrainz</dt>
                                                <dd><a href="https://musicbrainz.org/recording/{{ $recordingMbid }}" target="_blank" rel="noopener" class="text-gray-900 dark:text-gray-100 underline">Recording page</a></dd>
                                            @endif
                                        </dl>
                                    </div>
                                @endif
                                @if($lyrics)
                                    <div x-show="panel === 'lyrics'" x-cloak class="px-14 py-4 border-t border-gray-50 dark:border-stone-800/50 bg-gray-50/50 dark:bg-stone-800/20">
                                        <pre class="text-xs text-gray-600 dark:text-gray-400 whitespace-pre-wrap max-h-72 overflow-y-auto font-sans leading-relaxed">{{ $lyrics }}</pre>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    </div>
                @endif
            </x-card>

            @if($spotifyConnected)
                <livewire:album-more-on-spotify :album="$album" :key="'spotify-more-'.$album->id" />
            @endif
        </div>

        <!-- Right: Stats + recent plays -->
        <div class="space-y-6">

            @if($albumSound)
                <x-card class="p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Sound</h2>
                    <dl class="space-y-4 text-sm">
                        @if($albumSound['bpm'])
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Average tempo</dt>
                                <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $albumSound['bpm'] }} BPM</dd>
                            </div>
                        @endif
                        @if($albumSound['loudness'] !== null)
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Average loudness</dt>
                                <dd class="font-medium text-gray-800 dark:text-gray-200">{{ number_format($albumSound['loudness'], 1) }} LUFS</dd>
                            </div>
                        @endif
                        @if($albumSound['danceability'] !== null)
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Danceability</dt>
                                <dd class="font-medium text-gray-800 dark:text-gray-200">{{ round($albumSound['danceability'] * 100) }}%</dd>
                            </div>
                        @endif
                        @if($albumSound['keys'])
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Common keys</dt>
                                <dd class="font-medium text-gray-800 dark:text-gray-200 text-right">{{ implode(', ', $albumSound['keys']) }}</dd>
                            </div>
                        @endif
                        @if($albumSound['moods'])
                            <div style="display:flex;flex-wrap:wrap;gap:6px;padding-top:4px">
                                @foreach($albumSound['moods'] as $mood)
                                    <a href="{{ route('albums.index', ['mood' => $mood]) }}" class="text-xs font-medium bg-gray-100 dark:bg-stone-800 text-gray-600 dark:text-gray-400 hover:underline" style="padding:2px 10px;border-radius:9999px">{{ ucfirst($mood) }}</a>
                                @endforeach
                            </div>
                        @endif
                        <p class="text-xs text-gray-400 dark:text-gray-600">From {{ $albumSound['tracks'] }} of {{ $album->tracks->count() }} {{ Str::plural('track', $album->tracks->count()) }} analysed on the server.</p>
                    </dl>
                </x-card>
            @endif

            @php $albumCredits = (array) $details['credits']; @endphp
            @if($albumCredits)
                <x-card class="p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Credits</h2>
                    <dl class="space-y-3 text-sm">
                        @foreach($albumCredits as $role => $names)
                            <div>
                                <dt class="text-xs text-gray-400 dark:text-gray-600">{{ ucfirst($role) }}</dt>
                                <dd class="text-gray-800 dark:text-gray-200">{{ implode(', ', $names) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if($details['discogs_url'])
                        <a href="{{ $details['discogs_url'] }}" target="_blank" rel="noopener" class="inline-block mt-4 text-xs text-gray-900 dark:text-gray-100 underline">Discogs release</a>
                    @endif
                </x-card>
            @endif

            @if($totalPlays > 0)
                <x-card class="p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Stats</h2>
                    <dl class="space-y-4 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Total plays</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-200">{{ number_format($totalPlays) }}</dd>
                        </div>
                        @if($totalSeconds > 0)
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Listening time</dt>
                                <dd class="font-medium text-gray-800 dark:text-gray-200">{{ \App\Domain\Helpers\TimeHelper::humanDuration((int) $totalSeconds) }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Tracks played</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $album->tracks->filter(fn ($t) => $t->plays_count > 0)->count() }} / {{ $album->tracks->count() }}</dd>
                        </div>
                    </dl>
                </x-card>
            @endif

            @if($recentPlays->isNotEmpty())
                <x-card class="p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Recent Plays</h2>
                    <div class="space-y-3">
                        @foreach($recentPlays as $play)
                            @php
                                $playSource = $play->radioStation?->name
                                    ?? ($play->radio_name ? $play->radio_name : null)
                                    ?? match($play->source_type) {
                                        'spotify' => 'Spotify',
                                        'tidal'   => 'Tidal',
                                        'deezer'  => 'Deezer',
                                        default   => null,
                                    };
                            @endphp
                            <div class="flex items-center gap-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ $play->track->name }}</p>
                                    <p class="text-xs text-gray-400 dark:text-gray-600 truncate mt-0.5">
                                        {{ $play->played_at->format('M j, H:i') }}
                                        @if($play->device)
                                            &middot; {{ $play->device->device_name }}
                                        @endif
                                        @if($playSource)
                                            &middot; {{ $playSource }}
                                        @endif
                                    </p>
                                </div>
                                @if($play->skipped)
                                    <span class="text-[10px] text-amber-500 dark:text-amber-400 flex-shrink-0" title="Skipped">
                                        <i class="fa-solid fa-forward-step"></i>
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
