<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <x-back-button href="{{ route('settings.index') }}" />
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Client Devices</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">
                    {{ $clientCount }} {{ $clientCount === 1 ? 'client' : 'clients' }} registered
                    @if($pendingCount > 0)
                        &middot; <span class="text-amber-500">{{ $pendingCount }} pending approval</span>
                    @endif
                </p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl space-y-6">
        @if(session('success'))
            <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-2xl px-6 py-4 text-sm text-emerald-800 dark:text-emerald-300">
                <i class="fa-solid fa-circle-check mr-2"></i>{{ session('success') }}
            </div>
        @endif

        <livewire:client-manager />

        {{-- SD card artwork export (docs/architecture/sd-card-export.md) --}}
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 px-8 py-6">
            <div class="flex items-start justify-between gap-6">
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        <i class="fa-solid fa-sd-card mr-1.5 text-gray-400"></i>SD card artwork
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-1 max-w-xl">
                        Covers and backgrounds of the last {{ config('artwork.recent_albums') }} played albums plus the source logos,
                        in the touch client's SD layout. Extract the zip at the root of the card.
                        Also available as <code class="font-mono">php artisan artwork:export-sd</code>.
                    </p>
                    @if($artworkExport)
                        <p class="text-xs text-gray-400 dark:text-gray-600 mt-2" data-test="artwork-export-meta">
                            Last built {{ \Illuminate\Support\Carbon::parse($artworkExport['built_at'])->diffForHumans() }}
                            &middot; {{ \Illuminate\Support\Number::fileSize($artworkExport['bytes']) }}
                            &middot; {{ $artworkExport['albums'] }} {{ $artworkExport['albums'] === 1 ? 'album' : 'albums' }}, {{ $artworkExport['logos'] }} logos
                            @if($artworkExport['skipped'] > 0)
                                &middot; {{ $artworkExport['skipped'] }} not processed yet
                            @endif
                        </p>
                    @else
                        <p class="text-xs text-gray-300 dark:text-stone-600 mt-2">Never built</p>
                    @endif
                    @if($artworkExportPendingSince)
                        <p class="text-xs text-amber-500 mt-1"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Building… (requested {{ \Illuminate\Support\Carbon::parse($artworkExportPendingSince)->diffForHumans() }})</p>
                    @endif
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if($artworkExport)
                        <a href="{{ route('settings.clients.artwork-export.download') }}"
                           class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-gray-900 dark:bg-gray-100 dark:text-gray-900 hover:bg-gray-700 rounded-xl transition-colors">
                            <i class="fa-solid fa-download"></i>
                            Download
                        </a>
                    @endif
                    <form method="POST" action="{{ route('settings.clients.artwork-export') }}">
                        @csrf
                        <button type="submit" @disabled($artworkExportPendingSince)
                                class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 disabled:opacity-50 rounded-xl transition-colors">
                            <i class="fa-solid fa-box-archive"></i>
                            {{ $artworkExport ? 'Rebuild' : 'Build zip' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
