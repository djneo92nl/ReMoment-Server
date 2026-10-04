<x-app-layout>
    <x-slot name="header">
        <div class="flex items-start gap-4">
            <x-back-button href="{{ route('artists.show', $album->artist) }}" class="mt-1" />
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">{{ $album->name }}</h1>
                    <form method="POST" action="{{ route('albums.favorite', $album) }}">
                        @csrf
                        <button type="submit"
                                title="{{ $album->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}"
                                class="text-xl transition-colors {{ $album->favorited_at ? 'text-rose-500 hover:text-rose-600' : 'text-gray-300 hover:text-rose-400 dark:text-stone-600' }}">
                            <i class="{{ $album->favorited_at ? 'fa-solid' : 'fa-regular' }} fa-heart"></i>
                        </button>
                    </form>
                </div>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">
                    <a href="{{ route('artists.show', $album->artist) }}" class="hover:underline">{{ $album->artist->name }}</a>
                    @if($album->released_at)
                        &middot; {{ $album->released_at->format('Y') }}
                    @endif
                    @if($totalPlays > 0)
                        &middot; {{ number_format($totalPlays) }} {{ Str::plural('play', $totalPlays) }}
                    @endif
                </p>
            </div>
        </div>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">

        <!-- Left: Album art + tracklist -->
        <div class="lg:col-span-2 space-y-6">

            <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">

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
                        <p class="text-sm text-gray-500 dark:text-gray-500 mt-1">{{ $album->artist->name }}</p>
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
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 dark:bg-stone-800 text-gray-500 dark:text-gray-500">{{ $genre }}</span>
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
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-medium border border-gray-200 dark:border-stone-700 text-gray-500 dark:text-gray-500">{{ $tag }}</span>
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
                                    <a href="{{ $details['wikipedia_url'] }}" target="_blank" rel="noopener" class="text-indigo-600 dark:text-indigo-400 hover:underline">Wikipedia</a>
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
                                <button type="button"
                                        @click="$dispatch('open-modal', 'play-album-{{ $album->id }}')"
                                        class="flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition-colors">
                                    <i class="fa-solid fa-play text-xs"></i>
                                    Play all
                                </button>
                                <button type="button"
                                        @click="$dispatch('open-modal', 'shuffle-album-{{ $album->id }}')"
                                        class="flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 text-gray-700 dark:text-gray-300 text-sm font-medium transition-colors">
                                    <i class="fa-solid fa-shuffle text-xs"></i>
                                    Shuffle
                                </button>
                            </div>
                            <x-device-picker
                                name="play-album-{{ $album->id }}"
                                title="Play album"
                                :description="$album->name"
                                :devices="$playableDevices"
                                :action-template="url('albums/'.$album->id.'/play').'/{id}'"
                            />
                            <x-device-picker
                                name="shuffle-album-{{ $album->id }}"
                                title="Shuffle album"
                                :description="$album->name"
                                :devices="$playableDevices"
                                :action-template="url('albums/'.$album->id.'/play').'/{id}?shuffle=1'"
                            />
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
                        <div class="flex items-center justify-between gap-3 px-6 py-2.5 border-t border-gray-100 dark:border-stone-800 text-xs text-gray-500 dark:text-gray-500">
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
                                $recordingMbid = $track->metaValue('mbid');
                                $hasInfo = $quality || $credits || $trackGenres || $isrc || $popularity !== null || $listeners;
                            @endphp
                            <div x-data="{ panel: null }" @if($localCount > 0 && !$dlnaUrl) x-show="!localOnly" @endif>
                                <div class="flex items-center gap-4 px-6 py-3 hover:bg-gray-50 dark:hover:bg-stone-800/30 transition-colors group">
                                    <span class="w-5 text-center text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">{{ $track->metaValue('track_number') ?? $i + 1 }}</span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate flex items-center gap-1.5">
                                            {{ $track->name }}
                                            <x-source-icon :source="$track->source" />
                                            @if($track->metaValue('explicit') === '1')
                                                <span class="px-1 rounded bg-gray-200 dark:bg-stone-700 text-[9px] font-semibold text-gray-500 dark:text-gray-400" title="Explicit">E</span>
                                            @endif
                                        </p>
                                        @if($track->duration)
                                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">{{ gmdate('g:i', $track->duration) }}</p>
                                        @endif
                                    </div>
                                    @if(isset($track->plays_count) && $track->plays_count > 0)
                                        <span class="text-xs text-gray-300 dark:text-stone-600 flex-shrink-0">
                                            {{ number_format($track->plays_count) }}×
                                        </span>
                                    @endif
                                    @if($hasInfo)
                                        <button @click="panel = panel === 'info' ? null : 'info'"
                                                :class="panel === 'info' ? 'opacity-100 text-blue-500 dark:text-blue-400' : 'opacity-0 group-hover:opacity-100 text-gray-400'"
                                                class="transition-opacity w-7 h-7 rounded-full flex items-center justify-center hover:bg-gray-100 dark:hover:bg-stone-700 flex-shrink-0"
                                                title="Track details">
                                            <i class="fa-solid fa-circle-info text-xs"></i>
                                        </button>
                                    @endif
                                    @if($lyrics)
                                        <button @click="panel = panel === 'lyrics' ? null : 'lyrics'"
                                                :class="panel === 'lyrics' ? 'opacity-100 text-blue-500 dark:text-blue-400' : 'opacity-0 group-hover:opacity-100 text-gray-400'"
                                                class="transition-opacity w-7 h-7 rounded-full flex items-center justify-center hover:bg-gray-100 dark:hover:bg-stone-700 flex-shrink-0"
                                                title="Lyrics">
                                            <i class="fa-solid fa-align-left text-xs"></i>
                                        </button>
                                    @endif
                                    @if($dlnaUrl && $playableDevices->isNotEmpty())
                                        <button @click="$dispatch('open-modal', 'play-track-{{ $track->id }}')"
                                                class="opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity w-7 h-7 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-stone-700 flex-shrink-0"
                                                title="Play on device">
                                            <i class="fa-solid fa-play text-xs"></i>
                                        </button>
                                        <x-device-picker
                                            name="play-track-{{ $track->id }}"
                                            title="Play track"
                                            :description="$track->name"
                                            :devices="$playableDevices"
                                            :action-template="url('tracks/'.$track->id.'/play').'/{id}'"
                                        />
                                    @endif
                                </div>
                                @if($hasInfo)
                                    <div x-show="panel === 'info'" x-cloak class="px-14 py-4 border-t border-gray-50 dark:border-stone-800/50 bg-gray-50/50 dark:bg-stone-800/20">
                                        <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1.5 text-xs">
                                            @if($trackGenres)
                                                <dt class="text-gray-400 dark:text-gray-600">Genres</dt>
                                                <dd class="text-gray-700 dark:text-gray-300">{{ implode(', ', $trackGenres) }}</dd>
                                            @endif
                                            @if($quality)
                                                <dt class="text-gray-400 dark:text-gray-600">Quality</dt>
                                                <dd class="text-gray-700 dark:text-gray-300">{{ $quality }}</dd>
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
                                                <dd><a href="https://musicbrainz.org/recording/{{ $recordingMbid }}" target="_blank" rel="noopener" class="text-indigo-600 dark:text-indigo-400 hover:underline">Recording page</a></dd>
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
            </div>
        </div>

        <!-- Right: Stats + recent plays -->
        <div class="space-y-6">

            @php $albumCredits = (array) $details['credits']; @endphp
            @if($albumCredits)
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
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
                        <a href="{{ $details['discogs_url'] }}" target="_blank" rel="noopener" class="inline-block mt-4 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">Discogs release</a>
                    @endif
                </div>
            @endif

            @if($totalPlays > 0)
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
                    <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-5">Stats</h2>
                    <dl class="space-y-4 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-500">Total plays</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-200">{{ number_format($totalPlays) }}</dd>
                        </div>
                        @if($totalSeconds > 0)
                            @php $hours = floor($totalSeconds / 3600); $mins = floor(($totalSeconds % 3600) / 60); @endphp
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-500">Listening time</dt>
                                <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $hours > 0 ? "{$hours}h {$mins}m" : "{$mins}m" }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-500">Tracks played</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $album->tracks->filter(fn ($t) => $t->plays_count > 0)->count() }} / {{ $album->tracks->count() }}</dd>
                        </div>
                    </dl>
                </div>
            @endif

            @if($recentPlays->isNotEmpty())
                <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 p-6 md:p-8">
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
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
