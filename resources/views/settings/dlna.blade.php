<x-app-layout>
    <x-slot name="header">
        <x-page-header title="DLNA Library" subtitle="Media servers on your local network" :back="route('settings.index')">
            <x-slot name="actions">
                <form method="POST" action="{{ route('settings.dlna.discover') }}">
                    @csrf
                    <x-primary-button>
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <span class="hidden sm:inline">Discover servers</span>
                    </x-primary-button>
                </form>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-4xl space-y-6">

        @if(session('success'))
            <x-alert type="success">{{ session('success') }}</x-alert>
        @endif

        <x-alert type="info">
            Click <strong>Discover servers</strong> to find DLNA/UPnP media servers on your network, then
            <strong>Scan</strong> each server to index its music into the library. Alternatively run
            <code class="font-mono">php artisan library:scan</code> from the command line.
        </x-alert>

        <x-card flush>

            @if($servers->isEmpty())
                <x-empty-state bare icon="server" title="No DLNA servers found yet." text="Use \"Discover servers\" to scan your network." />
            @else
                <div class="grid grid-cols-12 gap-4 px-8 py-4 border-b border-gray-100 dark:border-stone-800">
                    <div class="col-span-4 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Server</div>
                    <div class="col-span-3 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Address</div>
                    <div class="col-span-3 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Last scanned</div>
                    <div class="col-span-2"></div>
                </div>

                @foreach($servers as $server)
                    <div class="grid grid-cols-12 gap-4 items-center px-8 py-5 border-b border-gray-50 dark:border-stone-800/50 last:border-0 hover:bg-gray-50/50 dark:hover:bg-stone-800/20 transition-colors">

                        <div class="col-span-4">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $server->friendly_name }}</p>
                        </div>

                        <div class="col-span-3">
                            <p class="text-sm text-gray-500 font-mono">{{ $server->ip }}:{{ $server->port }}</p>
                        </div>

                        <div class="col-span-3">
                            @if($server->last_scanned_at)
                                <span class="text-sm text-gray-500">{{ $server->last_scanned_at->diffForHumans() }}</span>
                            @else
                                <span class="text-sm text-gray-300 dark:text-stone-600">Never</span>
                            @endif
                        </div>

                        <div class="col-span-2 flex justify-end">
                            <form method="POST" action="{{ route('settings.dlna.scan', $server) }}">
                                @csrf
                                <x-secondary-button size="sm" type="submit">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                    Scan
                                </x-secondary-button>
                            </form>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-card>
    </div>
</x-app-layout>
