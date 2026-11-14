{{-- One export (the ExportService resource): status, progress, skipped, download parts, cancel/remove. --}}
@php
    $status = $export['status'];
    $variant = ['queued' => 'neutral', 'running' => 'info', 'done' => 'success', 'failed' => 'danger', 'expired' => 'outline'][$status] ?? 'neutral';
    $size = fn ($bytes) => $bytes >= 1024 ** 3 ? number_format($bytes / 1024 ** 3, 2).' GB' : number_format($bytes / 1024 ** 2, 1).' MB';
@endphp
<div class="space-y-3" data-test="export-{{ $export['id'] }}">
    <div class="flex flex-wrap items-center gap-3">
        <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $export['title'] }}</span>
        <x-badge :variant="$variant" data-test="status">{{ ucfirst($status) }}</x-badge>
        <x-badge variant="outline">{{ $profiles[$export['profile']] ?? $export['profile'] }}</x-badge>
        @if(in_array($status, ['queued', 'running']))
            <x-secondary-button size="sm" wire:click="cancelExport('{{ $export['id'] }}')" data-test="cancel"><i class="fa-solid fa-xmark"></i>Cancel</x-secondary-button>
        @else
            <x-secondary-button size="sm" wire:click="cancelExport('{{ $export['id'] }}')" wire:confirm="Remove this export and its files?" data-test="remove"><i class="fa-solid fa-trash"></i>Remove</x-secondary-button>
        @endif
    </div>

    @if(in_array($status, ['queued', 'running']))
        <div>
            <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-stone-800"><div class="h-full rounded-full bg-gray-900 dark:bg-gray-100" style="width: {{ $export['progress']['percent'] }}%"></div></div>
            <p class="mt-1 text-xs text-gray-500" data-test="progress">{{ $export['progress']['files_done'] }} of {{ $export['progress']['files_total'] }} files · {{ $export['progress']['percent'] }}%</p>
        </div>
    @endif

    @if($status === 'failed')
        <x-alert type="error">{{ $export['error'] ?: 'The export failed.' }}</x-alert>
    @elseif($status === 'expired')
        <p class="text-xs text-gray-500">The files were removed after {{ config('export.keep_hours') }} hours or to make room. Start the export again to get them.</p>
    @endif

    @if($status === 'done')
        <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-100 dark:divide-stone-800/70 dark:border-stone-800" data-test="parts">
            @foreach($export['parts'] as $part)
                <li class="flex items-center gap-3 px-4 py-2.5">
                    <span class="min-w-0 flex-1 truncate text-sm text-gray-800 dark:text-gray-200">{{ $part['name'] }}</span>
                    <span class="flex-none text-xs text-gray-500">{{ $part['files'] ?? '' }} files · {{ $size($part['size']) }}</span>
                    <x-secondary-button size="sm" :href="$part['url']" data-test="download-{{ $part['part'] }}"><i class="fa-solid fa-download"></i>Download</x-secondary-button>
                </li>
            @endforeach
        </ul>
        <p class="text-xs text-gray-500">Links work for 15 minutes; this page makes new ones.@if($export['expires_at']) Kept until {{ \Illuminate\Support\Carbon::parse($export['expires_at'])->diffForHumans() }}.@endif</p>
    @endif

    @if(count($export['skipped']))
        <details class="text-xs text-gray-500">
            <summary class="cursor-pointer">{{ count($export['skipped']) }} {{ Str::plural('track', count($export['skipped'])) }} not included</summary>
            <ul class="mt-1 space-y-0.5">@foreach($export['skipped'] as $s)<li>{{ $s['name'] ?? '#'.$s['track_id'] }} <span class="text-gray-400">({{ str_replace('_', ' ', $s['reason'] ?? '') }})</span></li>@endforeach</ul>
        </details>
    @endif
</div>
