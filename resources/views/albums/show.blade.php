<x-app-layout>
    @php
        $album = $page->album;
        $pinned = app(\App\Support\SelectedDevice::class)->device();
        $sourceNames = collect($page->sources)->mapWithKeys(fn ($s) => [$s => \App\Domain\Library\LibrarySources::LIBRARIES[$s]['name']]);
    @endphp

    <div class="space-y-8">
        <x-album-hero :page="$page" :devices="$playableDevices" :spotify-connected="$spotifyConnected" />

        @php
            $details = $page->details;
            $tabs = array_filter([
                'tracks' => ['Tracks', 'music'],
                'about' => $details['summary'] ? ['About', 'circle-info'] : null,
                'sound' => $page->sound ? ['Sound', 'sliders'] : null,
                'credits' => $page->credits ? ['Credits', 'users'] : null,
                'stats' => $page->totalPlays > 0 ? ['Stats', 'chart-simple'] : null,
                'plays' => $page->recentPlays->isNotEmpty() ? ['Recent plays', 'clock-rotate-left'] : null,
            ]);
            $albumSound = $page->sound;
            $dd = 'text-sm font-medium text-gray-800 dark:text-gray-200';
        @endphp

        <x-tabs :tabs="$tabs">
        <x-tabs.panel name="tracks" class="space-y-6">

        {{-- Tracklist --}}
        @if($page->tracks->isNotEmpty())
            <x-card flush>
                {{-- Source: tracks are never merged across sources; with several, the switch in the table header picks the list (and what Play plays) --}}
                @if(count($page->sources) > 1)
                    <nav class="flex gap-1 border-b border-gray-100 p-2 dark:border-stone-800/70" aria-label="Source">
                        @foreach($sourceNames as $key => $name)
                            <a href="{{ route('albums.show', [$album, 'source' => $key]) }}" data-test="source-{{ $key }}" @if($page->source === $key) aria-current="page" @endif
                               class="inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium transition-colors {{ $page->source === $key ? 'bg-gray-100 text-gray-900 dark:bg-stone-800 dark:text-gray-100' : 'text-gray-500 hover:text-gray-800 dark:hover:text-gray-200' }}">
                                <x-source-icon :source="$key" />{{ $name }}
                                <span class="text-xs font-normal text-gray-400 dark:text-gray-600">{{ $page->allTracks->filter(fn ($t) => in_array($key, $t->sources(), true))->count() }}</span>
                            </a>
                        @endforeach
                    </nav>
                @elseif($page->source)
                    <p class="flex items-center gap-2 border-b border-gray-100 px-5 py-3 text-sm text-gray-500 dark:border-stone-800/70"><x-source-icon :source="$page->source" />In {{ $sourceNames[$page->source] }}</p>
                @endif
                <div class="divide-y divide-gray-100 dark:divide-stone-800/70">
                @foreach($page->tracks as $i => $track)
                    @php
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
                        $plays = (int) ($track->plays_count ?? 0);
                        $hasInfo = $waveform || $quality || $credits || $trackGenres || $isrc || $popularity !== null || $listeners || $soundLine !== '' || ($sound && $sound['mood']) || $plays > 0;
                        $removable = auth()->check() && $track->isRemovable();
                        // With a player pinned, a play button only where it can play the track; without one the button opens the player panel.
                        $canPlayHere = $pinned ? ($page->playable[$track->id] ?? false) : \App\Domain\Library\LibraryPlayback::trackPlayable($track);
                        $canPickDevice = $playableDevices->isNotEmpty() && \App\Domain\Library\LibraryPlayback::trackPlayable($track);
                        $playAction = \App\Support\DeviceAction::template('tracks.play', ['track' => $track]);
                        $rowBtn = 'flex h-8 w-8 items-center justify-center rounded-full';
                    @endphp
                    <div id="track-{{ $track->id }}" class="scroll-mt-24" x-data="{ panel: null }">
                        <div class="group flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-gray-50 sm:px-5 dark:hover:bg-stone-800/30">
                            {{-- The number; the play button takes its place on hover or focus, and on touch screens --}}
                            <div class="relative h-8 w-8 flex-none">
                                <span class="absolute inset-0 flex items-center justify-center text-sm tabular-nums text-gray-400 dark:text-stone-500 {{ $canPlayHere ? 'group-hover:hidden group-focus-within:hidden [@media(hover:none)]:hidden' : '' }}">{{ $track->metaValue('track_number') ?? $i + 1 }}</span>
                                @if($canPlayHere)
                                    @if($pinned)
                                        <form method="POST" action="{{ str_replace('{id}', $pinned->id, $playAction) }}" class="hidden group-hover:block group-focus-within:block [@media(hover:none)]:block">
                                            @csrf
                                            <button type="submit" class="{{ $rowBtn }} text-gray-900 hover:bg-gray-200 dark:text-gray-100 dark:hover:bg-stone-700" title="Play on {{ $pinned->device_name }}" aria-label="Play {{ $track->name }} on {{ $pinned->device_name }}"><i class="fa-solid fa-play text-xs"></i></button>
                                        </form>
                                    @else
                                        <button type="button" class="{{ $rowBtn }} hidden text-gray-900 hover:bg-gray-200 group-hover:flex group-focus-within:flex dark:text-gray-100 dark:hover:bg-stone-700 [@media(hover:none)]:flex" @click="$dispatch('open-player-panel')" title="Choose a player to play on" aria-label="Choose a player to play {{ $track->name }} on"><i class="fa-solid fa-play text-xs"></i></button>
                                    @endif
                                @endif
                            </div>

                            <p class="flex min-w-0 flex-1 items-center gap-2 text-sm text-gray-900 dark:text-gray-100">
                                <span class="truncate">{{ $track->name }}</span>
                                @if($track->metaValue('explicit') === '1')
                                    <span class="flex-none rounded bg-gray-200 px-1 text-[9px] font-semibold text-gray-500 dark:bg-stone-700 dark:text-gray-400" title="Explicit">E</span>
                                @endif
                            </p>

                            {{-- The track's own waveform, a quiet hint of its shape --}}
                            @if($waveform)
                                <div class="hidden w-28 flex-none text-gray-500 sm:block dark:text-gray-400"><x-waveform :points="$waveform" :height="24" :opacity="0.5" /></div>
                            @endif

                            @if($badge = $track->qualityBadge())
                                <span class="hidden flex-none rounded-md border border-gray-300 px-1.5 py-0.5 text-[10px] font-medium leading-none text-gray-500 sm:inline dark:border-stone-600 dark:text-gray-400" title="{{ $quality }}">{{ $badge }}</span>
                            @endif


                            <form method="POST" action="{{ route('tracks.favorite', $track) }}" class="flex-none">
                                @csrf
                                <button type="submit" title="{{ $track->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}" aria-label="{{ $track->favorited_at ? 'Remove from favorites' : 'Add to favorites' }}"
                                        class="{{ $rowBtn }} text-sm transition {{ $track->favorited_at ? 'text-rose-500 hover:text-rose-600' : 'text-gray-400 opacity-0 hover:text-rose-400 focus:opacity-100 group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100' }}">
                                    <i class="{{ $track->favorited_at ? 'fa-solid' : 'fa-regular' }} fa-heart"></i>
                                </button>
                            </form>

                            <span class="w-10 flex-none text-right text-xs tabular-nums text-gray-400 dark:text-stone-500">{{ $track->duration ? \App\Domain\Helpers\TimeHelper::secondsToMinutes((int) $track->duration) : '' }}</span>

                            <x-menu label="Options" active="panel">
                                @if($canPickDevice)
                                    <x-menu.item icon="tv" @click="$dispatch('play-on-device', {{ \Illuminate\Support\Js::from(['action' => $playAction, 'label' => $track->name]) }})">Play on device&hellip;</x-menu.item>
                                @endif
                                <x-menu.item icon="list-ul" @click="$dispatch('add-to-playlist', {{ \Illuminate\Support\Js::from(['track_id' => $track->id, 'label' => $track->name]) }})">Add to playlist&hellip;</x-menu.item>
                                {{-- Queue ("play next", "add to queue") joins here once a pinned player reports `queue_add` --}}
                                @if($hasInfo)
                                    <x-menu.item icon="circle-info" @click="panel = panel === 'info' ? null : 'info'">Track details</x-menu.item>
                                @endif
                                @if($lyrics)
                                    <x-menu.item icon="align-left" @click="panel = panel === 'lyrics' ? null : 'lyrics'">Lyrics</x-menu.item>
                                @endif
                                @if($removable)
                                    <form method="POST" action="{{ route('tracks.destroy', $track) }}" onsubmit="return confirm('Remove this track from the library? Your play history is kept.')">
                                        @csrf
                                        @method('DELETE')
                                        <x-menu.item type="submit" icon="trash-can" danger>Remove from library</x-menu.item>
                                    </form>
                                @endif
                            </x-menu>
                        </div>
                        @if($hasInfo)
                            <div x-show="panel === 'info'" x-cloak class="px-5 sm:px-14 py-4 border-t border-gray-50 dark:border-stone-800/50 bg-gray-50/50 dark:bg-stone-800/20">
                                <dl class="text-xs" style="display:grid;grid-template-columns:max-content 1fr;gap:6px 24px">
                                    @if($plays > 0)
                                        <dt class="text-gray-400 dark:text-gray-600">Plays</dt>
                                        <dd class="text-gray-700 dark:text-gray-300">{{ number_format($plays) }}×</dd>
                                    @endif
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
                            <div x-show="panel === 'lyrics'" x-cloak class="px-5 sm:px-14 py-4 border-t border-gray-50 dark:border-stone-800/50 bg-gray-50/50 dark:bg-stone-800/20">
                                <pre class="text-xs text-gray-600 dark:text-gray-400 whitespace-pre-wrap max-h-72 overflow-y-auto font-sans leading-relaxed">{{ $lyrics }}</pre>
                            </div>
                        @endif
                    </div>
                @endforeach
                </div>
            </x-card>
        @else
            <x-empty-state icon="music" title="No tracks here" text="This album has no tracks from {{ $page->source ? $sourceNames[$page->source] : 'any source' }} yet." />
        @endif

        </x-tabs.panel>

        {{-- The other tabs: only those that have something to show --}}

                    @isset($tabs['about'])
                        <x-tabs.panel name="about" class="rounded-3xl border border-gray-200/70 bg-white p-5 shadow-sm sm:p-6 dark:border-stone-800 dark:bg-stone-900">
                            @if($details['summary'])
                                <p class="max-w-3xl text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ $details['summary'] }}
                                    @if($details['wikipedia_url'])
                                        <a href="{{ $details['wikipedia_url'] }}" target="_blank" rel="noopener" class="text-gray-900 underline dark:text-gray-100">Wikipedia</a>
                                    @endif
                                </p>
                            @endif
                        </x-tabs.panel>
                    @endisset

                    @isset($tabs['sound'])
                        <x-tabs.panel name="sound" class="rounded-3xl border border-gray-200/70 bg-white p-5 shadow-sm sm:p-6 dark:border-stone-800 dark:bg-stone-900">
                            <dl class="max-w-md space-y-4 text-sm">
                                @if($albumSound['bpm'])
                                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Average tempo</dt><dd class="{{ $dd }}">{{ $albumSound['bpm'] }} BPM</dd></div>
                                @endif
                                @if($albumSound['loudness'] !== null)
                                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Average loudness</dt><dd class="{{ $dd }}">{{ number_format($albumSound['loudness'], 1) }} LUFS</dd></div>
                                @endif
                                @if($albumSound['danceability'] !== null)
                                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Danceability</dt><dd class="{{ $dd }}">{{ round($albumSound['danceability'] * 100) }}%</dd></div>
                                @endif
                                @if($albumSound['keys'])
                                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Common keys</dt><dd class="{{ $dd }} text-right">{{ implode(', ', $albumSound['keys']) }}</dd></div>
                                @endif
                                @if($albumSound['moods'])
                                    <div class="flex flex-wrap gap-1.5 pt-1">
                                        @foreach($albumSound['moods'] as $mood)
                                            <a href="{{ route('albums.index', ['mood' => $mood]) }}" class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600 hover:underline dark:bg-stone-800 dark:text-gray-400">{{ ucfirst($mood) }}</a>
                                        @endforeach
                                    </div>
                                @endif
                                <p class="text-xs text-gray-400 dark:text-gray-600">From {{ $albumSound['tracks'] }} of {{ $page->allTracks->count() }} {{ Str::plural('track', $page->allTracks->count()) }} analysed on the server.</p>
                            </dl>
                        </x-tabs.panel>
                    @endisset

                    @isset($tabs['credits'])
                        <x-tabs.panel name="credits" class="rounded-3xl border border-gray-200/70 bg-white p-5 shadow-sm sm:p-6 dark:border-stone-800 dark:bg-stone-900">
                            <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                                @foreach($page->credits as $role => $names)
                                    <div>
                                        <dt class="text-xs text-gray-400 dark:text-gray-600">{{ ucfirst($role) }}</dt>
                                        <dd class="text-gray-800 dark:text-gray-200">{{ implode(', ', $names) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            @if($details['discogs_url'])
                                <a href="{{ $details['discogs_url'] }}" target="_blank" rel="noopener" class="mt-4 inline-block text-xs text-gray-900 underline dark:text-gray-100">Discogs release</a>
                            @endif
                        </x-tabs.panel>
                    @endisset

                    @isset($tabs['stats'])
                        <x-tabs.panel name="stats" class="rounded-3xl border border-gray-200/70 bg-white p-5 shadow-sm sm:p-6 dark:border-stone-800 dark:bg-stone-900">
                            <dl class="max-w-md space-y-4 text-sm">
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">Total plays</dt><dd class="{{ $dd }}">{{ number_format($page->totalPlays) }}</dd></div>
                                @if($page->listenedSeconds > 0)
                                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Listening time</dt><dd class="{{ $dd }}">{{ \App\Domain\Helpers\TimeHelper::humanDuration($page->listenedSeconds) }}</dd></div>
                                @endif
                                <div class="flex justify-between gap-4"><dt class="text-gray-500">Tracks played</dt><dd class="{{ $dd }}">{{ $page->allTracks->filter(fn ($t) => $t->plays_count > 0)->count() }} / {{ $page->allTracks->count() }}</dd></div>
                            </dl>
                        </x-tabs.panel>
                    @endisset

                    @isset($tabs['plays'])
                        <x-tabs.panel name="plays" class="rounded-3xl border border-gray-200/70 bg-white p-5 shadow-sm sm:p-6 dark:border-stone-800 dark:bg-stone-900">
                            <div class="grid gap-x-8 gap-y-3 md:grid-cols-2">
                                @foreach($page->recentPlays as $play)
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
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm text-gray-800 dark:text-gray-200">{{ $play->track->name }}</p>
                                            <p class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-600">
                                                {{ $play->played_at->format('M j, H:i') }}
                                                @if($play->device) &middot; {{ $play->device->device_name }} @endif
                                                @if($playSource) &middot; {{ $playSource }} @endif
                                            </p>
                                        </div>
                                        @if($play->skipped)
                                            <span class="flex-shrink-0 text-[10px] text-amber-500 dark:text-amber-400" title="Skipped"><i class="fa-solid fa-forward-step"></i></span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </x-tabs.panel>
                    @endisset
        </x-tabs>
    </div>

    <x-play-on-modal :devices="$playableDevices" />
    <x-playlist-picker :playlists="$editablePlaylists" />
</x-app-layout>
