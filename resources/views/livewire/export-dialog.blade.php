<div>
@if($visible)
    <x-secondary-button size="md" wire:click="toggle" data-test="export-toggle">
        <i class="fa-solid fa-file-zipper"></i>Export
    </x-secondary-button>

    @if($open)
        <x-card class="mt-4 space-y-4 p-5 sm:p-6" wire:poll.2s data-test="export-panel">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600"><i class="fa-solid fa-file-zipper mr-1.5"></i>Export for an MP3 player or car</h2>
                <button type="button" wire:click="toggle" class="text-xs text-gray-500 underline" data-test="export-close">Close</button>
            </div>

            @if($export)
                @include('livewire.partials.export-status', ['export' => $export, 'profiles' => $profiles])
                @if(!in_array($export['status'], ['queued', 'running']))
                    <x-secondary-button size="sm" wire:click="another" data-test="export-another">Export again with other settings</x-secondary-button>
                @endif
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="export-profile" value="Quality" />
                        <x-select id="export-profile" wire:model.live="profile" class="w-full" data-test="export-profile">
                            @foreach($profiles as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </x-select>
                    </div>
                    <div>
                        <x-input-label for="export-layout" value="Folders" />
                        <x-select id="export-layout" wire:model.live="layout" class="w-full">
                            <option value="">Automatic (artist/album for albums, flat for lists)</option>
                            <option value="tree">Artist / album folders</option>
                            <option value="flat">One flat folder, numbered</option>
                        </x-select>
                    </div>
                </div>

                @if($estimate)
                    @php($t = $estimate['totals'])
                    <div class="rounded-2xl border border-gray-100 p-4 text-sm dark:border-stone-800" data-test="export-estimate">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $estimate['title'] }}</p>
                        <p class="mt-1 text-gray-600 dark:text-gray-400">
                            {{ $t['exportable'] }} of {{ $t['tracks'] }} {{ Str::plural('track', $t['tracks']) }} can be exported,
                            about {{ $t['estimated_bytes'] >= 1024 ** 3 ? number_format($t['estimated_bytes'] / 1024 ** 3, 2).' GB' : number_format($t['estimated_bytes'] / 1024 ** 2, 1).' MB' }}
                            in {{ $t['parts'] }} {{ Str::plural('zip', $t['parts']) }}.
                        </p>
                        <p class="mt-1 text-gray-600 dark:text-gray-400"><span data-test="copied">{{ $t['copied'] }}</span> copied as they are, <span data-test="transcoded">{{ $t['transcoded'] }}</span> converted to MP3, <span data-test="skipped">{{ $t['skipped'] }}</span> skipped.</p>
                        @if(count($estimate['skipped']))
                            <details class="mt-2 text-xs text-gray-500">
                                <summary class="cursor-pointer">Skipped tracks</summary>
                                <ul class="mt-1 space-y-0.5">@foreach($estimate['skipped'] as $s)<li>{{ $s['name'] ?? '#'.$s['track_id'] }} <span class="text-gray-400">({{ str_replace('_', ' ', $s['reason'] ?? '') }})</span></li>@endforeach</ul>
                            </details>
                        @endif
                    </div>
                    @if($t['exceeds_limits'])
                        <x-alert type="warning">This is over the size limit ({{ implode(', ', $t['exceeds']) }}). Choose less or a lower quality.</x-alert>
                    @endif
                    @if($error)<x-alert type="error" data-test="export-error">{{ $error }}</x-alert>@endif
                    <x-primary-button size="md" wire:click="start" :disabled="$t['exportable'] === 0 || $t['exceeds_limits']" data-test="export-start"><i class="fa-solid fa-play"></i>Start export</x-primary-button>
                @endif
            @endif
        </x-card>
    @endif
@endif
</div>
