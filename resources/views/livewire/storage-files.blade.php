<div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">
    <div class="px-8 py-6 border-b border-gray-100 dark:border-stone-800">
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Files</h2>
        <p class="text-sm text-gray-500 dark:text-gray-500 mt-0.5">
            {{ number_format($total) }} {{ $total === 1 ? 'file' : 'files' }}
            @if($missing > 0)&middot; <span class="text-amber-500">{{ $missing }} missing</span> @endif
        </p>
        <div class="mt-4 flex flex-wrap gap-3">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search path…" data-test="file-search"
                   class="flex-1 min-w-48 px-3 py-2 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 rounded-xl text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
            <select wire:model.live="locationFilter" class="px-3 py-2 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 rounded-xl text-gray-900 dark:text-gray-100">
                <option value="">All locations</option>
                @foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
            </select>
            <select wire:model.live="statusFilter" class="px-3 py-2 text-sm bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 rounded-xl text-gray-900 dark:text-gray-100">
                <option value="">Any status</option>
                <option value="present">Present</option>
                <option value="missing">Missing</option>
            </select>
        </div>
    </div>

    @if(session('files-success'))
        <div class="px-8 py-3 text-sm text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-900/20"><i class="fa-solid fa-circle-check mr-2"></i>{{ session('files-success') }}</div>
    @endif
    @if(session('files-error'))
        <div class="px-8 py-3 text-sm text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-900/20"><i class="fa-solid fa-circle-exclamation mr-2"></i>{{ session('files-error') }}</div>
    @endif

    @if($files->isEmpty())
        <div class="px-8 py-12 text-sm text-gray-400 dark:text-gray-600 text-center">No files.</div>
    @else
        <div class="divide-y divide-gray-50 dark:divide-stone-800/50">
            @foreach($files as $file)
                <div class="px-8 py-4" data-test="file-{{ $file->id }}" wire:key="file-{{ $file->id }}">
                    @if($renaming === $file->id)
                        <div class="flex items-center gap-2">
                            <input type="text" wire:model="renamePath" wire:keydown.enter="saveRename" class="flex-1 px-3 py-2 text-sm font-mono bg-gray-50 dark:bg-stone-800 border border-gray-200 dark:border-stone-700 rounded-xl text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
                            <button wire:click="saveRename" class="px-3 py-2 text-xs font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl">Move</button>
                            <button wire:click="cancelRename" class="px-3 py-2 text-xs text-gray-500">Cancel</button>
                        </div>
                    @else
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm text-gray-900 dark:text-gray-100 font-mono truncate">{{ $file->path }}</p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ $file->location->name }}
                                    &middot; {{ \Illuminate\Support\Number::fileSize($file->size) }}
                                    @if($file->codec)&middot; {{ $file->codec }} @endif
                                    @if($file->bitrate)&middot; {{ round($file->bitrate / 1000) }} kbps @endif
                                    @if($file->status === 'missing')&middot; <span class="text-amber-500">missing</span> @endif
                                    @if($track = $file->tracks->first())&middot; {{ $track->name }} @else&middot; <span class="text-gray-400">no track</span> @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <button wire:click="verify({{ $file->id }})" class="px-2.5 py-1 text-xs text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 rounded-lg">Verify</button>
                                <button wire:click="startRename({{ $file->id }})" class="px-2.5 py-1 text-xs text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 rounded-lg">Move</button>
                                <button wire:click="delete({{ $file->id }})" wire:confirm="Delete this file from storage? This cannot be undone." class="px-2.5 py-1 text-xs text-gray-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg">Delete</button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="px-8 py-4">{{ $files->links() }}</div>
    @endif
</div>
