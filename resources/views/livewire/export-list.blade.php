<div @if($active) wire:poll.3s @else wire:poll.30s @endif>
    @auth
    <x-card class="space-y-4 p-5 sm:p-6">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-sm font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600"><i class="fa-solid fa-file-zipper mr-1.5"></i>Exports</h2>
            <p class="text-xs text-gray-500">{{ number_format($used / 1024 ** 3, 2) }} of {{ (float) config('export.dir_max_gb') }} GB used · kept {{ config('export.keep_hours') }} hours</p>
        </div>
        @php($profiles = collect(config('export.profiles'))->map(fn ($p) => $p['label'])->all())
        @forelse($exports as $export)
            @include('livewire.partials.export-status', ['export' => $export, 'profiles' => $profiles])
        @empty
            <p class="text-sm text-gray-500" data-test="no-exports">No exports yet. Use the Export button on an album, artist or playlist page.</p>
        @endforelse
    </x-card>
    @endauth
</div>
