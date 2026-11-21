<div @if($weights && $weights['state'] === 'downloading') wire:poll.2s @else wire:poll.15s @endif data-test="stem-weights">
    @if($configured)
        <x-card class="p-6 sm:p-8">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Stem separation weights</h2>
                @if($weights)
                    @switch($weights['state'])
                        @case('ready')
                            <x-badge variant="success" data-test="weights-state"><i class="fa-solid fa-check"></i>ready</x-badge>
                            @break
                        @case('downloading')
                            <x-badge variant="info" data-test="weights-state"><i class="fa-solid fa-gear fa-spin"></i>downloading</x-badge>
                            @break
                        @case('failed')
                            <x-badge variant="danger" data-test="weights-state">failed</x-badge>
                            @break
                        @default
                            <x-badge variant="warning" data-test="weights-state">not downloaded</x-badge>
                    @endswitch
                @endif
            </div>

            <p class="mt-2 text-sm text-gray-500">
                Splitting a track into vocals, drums, bass and other uses the Demucs model. Its weights ({{ \Illuminate\Support\Number::fileSize((int) ($weights['total'] ?? 84141911), 0) }}) are not part of ReMoment and are never downloaded by themselves: you decide.
            </p>

            <x-alert type="warning" class="mt-4" data-test="weights-licence">
                {{ $licenceNote }}
                <a href="{{ $licenceUrl }}" target="_blank" rel="noopener" class="underline">Read the licence note</a>
            </x-alert>

            @if($problem)
                <x-alert type="error" class="mt-4" data-test="weights-problem">{{ $problem }}</x-alert>
            @endif
            @if($noticeError)
                <x-alert type="error" class="mt-4" data-test="weights-error">{{ $noticeError }}</x-alert>
            @elseif($notice)
                <x-alert type="success" class="mt-4" data-test="weights-notice">{{ $notice }}</x-alert>
            @endif

            @if($weights)
                @if($weights['state'] === 'downloading')
                    <div class="mt-4" data-test="weights-progress">
                        <div class="flex h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-stone-800" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $weights['percent'] ?? 0 }}" aria-label="Weights downloaded">
                            <div class="bg-emerald-500" style="width: {{ $weights['percent'] ?? 0 }}%"></div>
                        </div>
                        <p class="mt-1 text-xs tabular-nums text-gray-500">
                            {{ \Illuminate\Support\Number::fileSize($weights['downloaded'], 1) }}@if($weights['total']) of {{ \Illuminate\Support\Number::fileSize($weights['total'], 1) }} ({{ $weights['percent'] ?? 0 }}%)@endif
                        </p>
                    </div>
                @elseif($weights['state'] === 'ready')
                    <p class="mt-4 text-sm text-gray-500" data-test="weights-size">Downloaded and checked ({{ \Illuminate\Support\Number::fileSize((int) $weights['size'], 1) }}, SHA-256 {{ substr((string) $weights['sha256'], 0, 12) }}…). Stems can be requested for a track.</p>
                @elseif($weights['state'] === 'failed')
                    <x-alert type="error" class="mt-4" data-test="weights-failed">The download failed: {{ $weights['error'] }}</x-alert>
                @endif

                @if(in_array($weights['state'], ['missing', 'failed'], true))
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        @auth
                            <x-primary-button size="md" type="button" wire:click="download" wire:loading.attr="disabled" data-test="weights-download">
                                <i class="fa-solid fa-download"></i>{{ $weights['state'] === 'failed' ? 'Try again' : 'Download the weights' }}
                            </x-primary-button>
                            <span class="text-xs text-gray-500">From {{ parse_url((string) $weights['source'], PHP_URL_HOST) }}, checked against a fixed SHA-256.</span>
                        @else
                            <span class="text-sm text-gray-500" data-test="weights-login">Sign in as admin to start the download.</span>
                        @endauth
                    </div>
                @endif
            @endif
        </x-card>
    @endif
</div>
