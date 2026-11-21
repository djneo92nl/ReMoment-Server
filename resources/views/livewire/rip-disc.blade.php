<div @if($active) wire:poll.3s @else wire:poll.20s @endif>
    @if($cards)
        <x-card class="space-y-5 p-5 sm:p-6" data-test="rip-disc">
            <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600"><i class="fa-solid fa-compact-disc mr-1.5"></i>Rip this disc</h2>
            @if($error)<x-alert type="error" data-test="rip-error">{{ $error }}</x-alert>@endif

            @foreach($cards as $card)
                @php($node = $card['node'])
                @php($job = $card['job'])
                @php($state = $job['state'] ?? null)
                @php($working = in_array($state, ['probing', 'looking_up', 'queued', 'ripping', 'uploading'], true))
                <div class="space-y-3" data-test="ripper-{{ $node['id'] }}">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $node['name'] }}</span>
                        @if(!$node['node_online'])
                            <x-badge variant="danger">Offline</x-badge>
                        @elseif($node['node_state'] === 'absent')
                            <x-badge variant="outline">No drive</x-badge>
                        @else
                            <x-badge variant="neutral">{{ ucfirst($node['node_state']) }}</x-badge>
                        @endif
                        @auth
                            @if(!$working)
                                <x-secondary-button size="sm" wire:click="check({{ $node['id'] }})" :disabled="!$node['node_online']" data-test="check"><i class="fa-solid fa-magnifying-glass"></i>Check the disc</x-secondary-button>
                            @endif
                        @endauth
                    </div>

                    @guest<p class="text-xs text-gray-500">Sign in as the admin to rip a disc.</p>@endguest

                    @if($state === 'probing' || $state === 'looking_up')
                        <p class="text-sm text-gray-500" data-test="probing">{{ $state === 'probing' ? 'Reading the disc …' : 'Looking the disc up on MusicBrainz …' }}</p>
                    @elseif($state === 'no_disc')
                        <p class="text-sm text-gray-500" data-test="no-disc">There is no disc in the drive.</p>
                    @elseif($state === 'not_audio')
                        <p class="text-sm text-gray-500">The disc in the drive is not an audio CD.</p>
                    @elseif($state === 'choose_release')
                        @if($job['lookup_error'])<x-alert type="warning">MusicBrainz could not be reached: {{ $job['lookup_error'] }}</x-alert>@endif
                        @if($job['candidates'])
                            <p class="text-xs text-gray-500">{{ $job['disc']['tracks'] }} tracks. Choose the release this disc is.</p>
                            <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-100 dark:divide-stone-800/70 dark:border-stone-800" data-test="rip-candidates">
                                @foreach($job['candidates'] as $c)
                                    <li class="flex items-center gap-3 px-4 py-2.5">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $c['title'] }}</p>
                                            <p class="truncate text-xs text-gray-400 dark:text-gray-600">{{ $c['artist'] }}{{ $c['year'] ? ' · '.$c['year'] : '' }}{{ $c['label'] ? ' · '.$c['label'] : '' }}{{ $c['country'] ? ' · '.$c['country'] : '' }}{{ $c['barcode'] ? ' · '.$c['barcode'] : '' }}</p>
                                        </div>
                                        @auth<x-primary-button size="sm" wire:click="rip({{ $node['id'] }}, '{{ $c['release_mbid'] }}')" data-test="rip-{{ $c['release_mbid'] }}"><i class="fa-solid fa-compact-disc"></i>Rip this</x-primary-button>@endauth
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-sm text-gray-500" data-test="no-candidates">MusicBrainz does not know this disc. Paste the id of the release it is (add the CD below with its barcode to find it).</p>
                        @endif
                        @auth
                            <form wire:submit="ripManual({{ $node['id'] }})" class="flex flex-wrap items-end gap-3">
                                <div class="min-w-0 flex-1">
                                    <x-input-label for="manual-{{ $node['id'] }}" value="Or a MusicBrainz release id" />
                                    <x-text-input id="manual-{{ $node['id'] }}" wire:model="manual.{{ $node['id'] }}" class="w-full" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                                </div>
                                <x-secondary-button type="submit" size="md"><i class="fa-solid fa-compact-disc"></i>Rip</x-secondary-button>
                            </form>
                        @endauth
                    @elseif(in_array($state, ['queued', 'ripping', 'uploading'], true))
                        <div data-test="rip-progress">
                            <p class="mb-1 truncate text-sm text-gray-800 dark:text-gray-200">{{ $job['release']['artist'] }} – {{ $job['release']['title'] }}</p>
                            <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-stone-800"><div class="h-full rounded-full bg-gray-900 dark:bg-gray-100" style="width: {{ $job['percent'] ?? 0 }}%"></div></div>
                            <p class="mt-1 flex items-center gap-3 text-xs text-gray-500">
                                <span class="flex-1" data-test="rip-state">{{ match($state) { 'queued' => 'Waiting for the node …', 'uploading' => 'Uploading track '.($job['progress']['track'] ?? 0).' of '.($job['progress']['tracks'] ?? 0), default => 'Ripping track '.($job['progress']['track'] ?? 0).' of '.($job['progress']['tracks'] ?? $job['release']['tracks']) } }}{{ $job['percent'] !== null ? ' · '.$job['percent'].'%' : '' }}</span>
                                @auth<button type="button" wire:click="cancel('{{ $job['id'] }}')" class="text-xs underline" data-test="rip-cancel">Cancel</button>@endauth
                            </p>
                        </div>
                    @elseif($state === 'done')
                        <x-alert type="success" data-test="rip-done">Ripped {{ $job['release']['artist'] }} – {{ $job['release']['title'] }}: {{ count($job['files']) }} files are in the library.</x-alert>
                        @foreach($job['warnings'] as $w)<p class="text-xs text-amber-600 dark:text-amber-400">{{ $w }}</p>@endforeach
                    @elseif($state === 'failed')
                        <x-alert type="error" data-test="rip-failed">The {{ $job['kind'] === 'rip' ? 'rip' : 'check' }} failed: {{ $job['error'] ?: 'the node did not answer.' }}</x-alert>
                    @elseif($state === 'cancelled')
                        <p class="text-sm text-gray-500">Cancelled.</p>
                    @elseif($node['disc'] === null)
                        <p class="text-sm text-gray-500">Put a disc in the drive, then check it.</p>
                    @endif
                </div>
            @endforeach
        </x-card>
    @endif
</div>
