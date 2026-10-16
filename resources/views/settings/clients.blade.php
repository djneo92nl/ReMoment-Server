<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Client Devices" :back="route('settings.index')">
            <x-slot name="meta">
                {{ $clientCount }} {{ $clientCount === 1 ? 'client' : 'clients' }} registered
                @if($pendingCount > 0)
                    &middot; <span class="text-amber-500">{{ $pendingCount }} pending approval</span>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-4xl space-y-6">
        <x-alert type="success">{{ session('success') }}</x-alert>

        <livewire:client-manager />

        {{-- SD card artwork export, one zip per background size (docs/architecture/sd-card-export.md) --}}
        <x-card class="px-8 py-6">
            <div class="flex items-start justify-between gap-6">
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        <i class="fa-solid fa-sd-card mr-1.5 text-gray-400"></i>SD card artwork
                    </p>
                    <p class="text-xs text-gray-500 mt-1 max-w-xl">
                        Covers and backgrounds of the last {{ config('artwork.recent_albums') }} played albums plus the source logos,
                        in the touch client's SD layout, with the backgrounds for one screen size. Extract the zip at the root of the card.
                        Also available as <code class="font-mono">php artisan artwork:export-sd --size=1024x600</code>.
                    </p>
                </div>
                <form method="POST" action="{{ route('settings.clients.artwork-export') }}" class="flex shrink-0 items-center gap-2">
                    @csrf
                    <x-select size="sm" name="size" aria-label="Screen size" data-test="artwork-export-size">
                        @foreach($artworkExports as $size => $export)
                            <option value="{{ $size }}">{{ str_replace('x', '×', $size) }} &middot; {{ $export['label'] }}</option>
                        @endforeach
                    </x-select>
                    <x-secondary-button size="sm" type="submit">
                        <i class="fa-solid fa-box-archive"></i>
                        Build zip
                    </x-secondary-button>
                </form>
            </div>

            <div class="mt-4 divide-y divide-gray-100 dark:divide-stone-800">
                @foreach($artworkExports as $size => $export)
                    <div class="flex items-center justify-between gap-6 py-3" data-test="artwork-export-{{ $size }}">
                        <div>
                            <p class="text-xs font-medium text-gray-700 dark:text-gray-300">
                                {{ str_replace('x', '×', $size) }} <span class="font-normal text-gray-400 dark:text-gray-600">&middot; {{ $export['label'] }}</span>
                            </p>
                            @if($meta = $export['meta'])
                                <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5" data-test="artwork-export-meta">
                                    Last built {{ \Illuminate\Support\Carbon::parse($meta['built_at'])->diffForHumans() }}
                                    &middot; {{ \Illuminate\Support\Number::fileSize($meta['bytes']) }}
                                    &middot; {{ $meta['albums'] }} {{ $meta['albums'] === 1 ? 'album' : 'albums' }}, {{ $meta['logos'] }} logos
                                    @if($meta['skipped'] > 0)
                                        &middot; {{ $meta['skipped'] }} not processed yet
                                    @endif
                                </p>
                            @else
                                <p class="text-xs text-gray-300 dark:text-stone-600 mt-0.5">Never built</p>
                            @endif
                            @if($export['pending_since'])
                                <p class="text-xs text-amber-500 mt-0.5"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Building… (requested {{ \Illuminate\Support\Carbon::parse($export['pending_since'])->diffForHumans() }})</p>
                            @endif
                        </div>
                        @if($export['meta'])
                            <x-primary-button size="sm" href="{{ route('settings.clients.artwork-export.download', ['size' => $size]) }}">
                                <i class="fa-solid fa-download"></i>
                                Download
                            </x-primary-button>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-card>
    </div>
</x-app-layout>
