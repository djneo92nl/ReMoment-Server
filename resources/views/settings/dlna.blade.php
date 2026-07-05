<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <x-back-button href="{{ route('settings.index') }}" />
                <div>
                    <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">DLNA Library</h1>
                    <p class="mt-1.5 text-gray-500 dark:text-gray-500">Media servers on your local network</p>
                </div>
            </div>

            <form method="POST" action="{{ route('settings.dlna.discover') }}">
                @csrf
                <button type="submit"
                        class="flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl text-sm font-medium transition-colors">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span class="hidden sm:inline">Discover servers</span>
                </button>
            </form>
        </div>
    </x-slot>

    <div class="max-w-4xl space-y-6">

        @if(session('success'))
            <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-2xl px-6 py-4 text-sm text-emerald-800 dark:text-emerald-300">
                <i class="fa-solid fa-circle-check mr-2"></i>{{ session('success') }}
            </div>
        @endif

        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-2xl px-6 py-4 text-sm text-blue-800 dark:text-blue-300">
            <i class="fa-solid fa-circle-info mr-2"></i>
            Click <strong>Discover servers</strong> to find DLNA/UPnP media servers on your network, then
            <strong>Scan</strong> each server to index its music into the library. Alternatively run
            <code class="font-mono bg-blue-100 dark:bg-blue-900 px-1 rounded">php artisan library:scan</code> from the command line.
        </div>

        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">

            @if($servers->isEmpty())
                <div class="flex flex-col items-center justify-center py-20 text-center px-8">
                    <i class="fa-solid fa-server text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-500">No DLNA servers found yet.</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Use "Discover servers" to scan your network.</p>
                </div>
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
                            <p class="text-sm text-gray-500 dark:text-gray-500 font-mono">{{ $server->ip }}:{{ $server->port }}</p>
                        </div>

                        <div class="col-span-3">
                            @if($server->last_scanned_at)
                                <span class="text-sm text-gray-500 dark:text-gray-500">{{ $server->last_scanned_at->diffForHumans() }}</span>
                            @else
                                <span class="text-sm text-gray-300 dark:text-stone-600">Never</span>
                            @endif
                        </div>

                        <div class="col-span-2 flex justify-end">
                            <form method="POST" action="{{ route('settings.dlna.scan', $server) }}">
                                @csrf
                                <button type="submit"
                                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 rounded-xl transition-colors">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                    Scan
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</x-app-layout>
