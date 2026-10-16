<div class="space-y-6" @if($polling) wire:poll.2s @endif>

    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif
    @if(session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    <x-card flush>
        <div class="px-8 py-6 border-b border-gray-100 dark:border-stone-800 flex items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Storage locations</h2>
                <p class="text-sm text-gray-500 mt-0.5">
                    The <strong>library</strong> location holds the organized files; an <strong>inbox</strong> location is where imports arrive.
                </p>
            </div>
            <x-primary-button size="md" wire:click="newLocation" data-test="new-location">
                <i class="fa-solid fa-plus"></i><span class="hidden sm:inline">Add location</span>
            </x-primary-button>
        </div>

        @if($editing !== null)
            <div style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0,0,0,.5);" wire:keydown.escape="cancel">
            <div class="w-full max-w-2xl max-h-full overflow-y-auto bg-white dark:bg-stone-900 rounded-3xl shadow-2xl border border-gray-200 dark:border-stone-700 px-8 py-6 space-y-4" data-test="location-form">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ is_int($editing) ? 'Edit location' : 'Add location' }}</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1.5">Name</label>
                        <x-text-input size="sm" type="text" wire:model="name" placeholder="e.g. Music NAS" class="w-full" />
                        @error('name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1.5">Type</label>
                        <x-select size="sm" wire:model.live="driver" class="w-full">
                            <option value="local">Local or mounted folder</option>
                            <option value="s3">S3-compatible</option>
                        </x-select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1.5">Role</label>
                        <x-select size="sm" wire:model="role" class="w-full">
                            <option value="library">Library (organized files)</option>
                            <option value="inbox">Inbox (files to import)</option>
                        </x-select>
                    </div>
                </div>

                @if($driver === 'local')
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1.5">Folder on the server</label>
                        <x-text-input size="sm" type="text" wire:model="root" placeholder="/mnt/music" class="w-full" />
                        @error('root')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                        <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">A NAS share works once the operating system has mounted it (SMB/NFS).</p>
                    </div>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1.5">Bucket</label>
                            <x-text-input size="sm" type="text" wire:model="bucket" class="w-full" />
                            @error('bucket')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1.5">Folder in the bucket <span class="font-normal">(optional)</span></label>
                            <x-text-input size="sm" type="text" wire:model="prefix" placeholder="music" class="w-full" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1.5">Endpoint <span class="font-normal">(MinIO, B2, … leave empty for AWS)</span></label>
                            <x-text-input size="sm" type="text" wire:model="endpoint" placeholder="http://minio:9000" class="w-full" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1.5">Region</label>
                            <x-text-input size="sm" type="text" wire:model="region" placeholder="us-east-1" class="w-full" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1.5">Access key</label>
                            <x-text-input size="sm" type="text" wire:model="key" autocomplete="off" placeholder="{{ is_int($editing) ? 'unchanged' : '' }}" class="w-full" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1.5">Secret key</label>
                            <x-text-input size="sm" type="password" wire:model="secret" autocomplete="new-password" placeholder="{{ is_int($editing) ? 'unchanged' : '' }}" class="w-full" />
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <input type="checkbox" wire:model="pathStyle" class="rounded border-gray-300 dark:border-stone-600" />
                        Path-style addressing (needed by MinIO and most self-hosted S3)
                    </label>
                @endif

                <div class="flex items-center gap-3">
                    <x-primary-button size="md" wire:click="save">Save</x-primary-button>
                    <button wire:click="cancel" class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors">Cancel</button>
                </div>
            </div>
            </div>
        @endif

        @if($locations->isEmpty())
            <div class="flex flex-col items-center justify-center py-16 text-center px-8">
                <i class="fa-solid fa-hard-drive text-4xl text-gray-200 dark:text-stone-700 mb-4"></i>
                <p class="text-gray-500">No storage locations yet.</p>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">Add the folder or bucket your music lives in.</p>
            </div>
        @else
            <div class="divide-y divide-gray-50 dark:divide-stone-800/50">
                @foreach($locations as $location)
                    @php($status = $scanStatus[$location->id])
                    <div class="px-8 py-5 flex flex-wrap items-center justify-between gap-4" data-test="location-{{ $location->id }}">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2.5 flex-wrap">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-xl text-xs font-medium {{ $location->role === 'library' ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400' : 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400' }}">
                                    {{ $location->role }}
                                </span>
                                <span class="text-base font-medium text-gray-900 dark:text-gray-100">{{ $location->name }}</span>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-500 font-mono truncate">
                                @if($location->driver === 'local')
                                    {{ $location->config['root'] ?? '' }}
                                @else
                                    s3://{{ $location->config['bucket'] ?? '' }}{{ !empty($location->config['prefix']) ? '/'.$location->config['prefix'] : '' }}
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-600">
                                {{ number_format($location->files_count) }} {{ $location->files_count === 1 ? 'file' : 'files' }}
                                &middot; {{ $location->last_scanned_at ? 'scanned '.$location->last_scanned_at->diffForHumans() : 'never scanned' }}
                            </p>
                            @if($status)
                                <p class="mt-1 text-xs {{ $status['state'] === 'failed' ? 'text-red-500' : (in_array($status['state'], ['queued', 'running']) ? 'text-amber-500' : 'text-gray-400 dark:text-gray-600') }}" data-test="scan-status-{{ $location->id }}">
                                    @if($status['state'] === 'queued')
                                        <i class="fa-solid fa-clock mr-1"></i>Scan queued…
                                    @elseif($status['state'] === 'running')
                                        <i class="fa-solid fa-spinner fa-spin mr-1"></i>Scanning…
                                        @isset($status['handled']) {{ $status['handled'] }} indexed, now {{ \Illuminate\Support\Str::limit($status['current'] ?? '', 40) }} @endisset
                                    @elseif($status['state'] === 'failed')
                                        <i class="fa-solid fa-circle-exclamation mr-1"></i>Scan failed: {{ $status['message'] ?? '' }}
                                    @else
                                        Last scan: {{ $status['message'] ?? '' }}
                                    @endif
                                </p>
                            @endif
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button wire:click="scan({{ $location->id }})" class="px-3 py-1.5 text-xs font-medium text-white bg-gray-900 dark:bg-gray-100 dark:text-gray-900 hover:bg-gray-700 rounded-xl transition-colors"><i class="fa-solid fa-rotate mr-1"></i>Scan</button>
                            <button wire:click="test({{ $location->id }})" class="px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 rounded-xl transition-colors">Test</button>
                            <button wire:click="edit({{ $location->id }})" class="px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 rounded-xl transition-colors">Edit</button>
                            <button wire:click="delete({{ $location->id }})" wire:confirm="Remove {{ $location->name }}? Its files are not touched." class="px-3 py-1.5 text-xs font-medium text-gray-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-xl transition-colors">Remove</button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>

    <x-card class="px-8 py-6 space-y-4">
        <p class="text-sm font-medium text-gray-900 dark:text-gray-100"><i class="fa-solid fa-folder-tree mr-1.5 text-gray-400"></i>Library tasks</p>
        @foreach([
            ['import', 'Import inbox', 'Moves the audio files in the inbox into the library, organized by artist and album.', $importStatus, $hasInbox],
            ['reorganize', 'Reorganize library', 'Moves every library file to its {artist}/{album}/{track} path, for example after tags changed.', $reorganizeStatus, true],
        ] as [$method, $label, $help, $taskStatus, $enabled])
            <div class="flex items-center justify-between gap-6" data-test="task-{{ $method }}">
                <div>
                    <p class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ $label }}</p>
                    <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5">{{ $help }}</p>
                    @if($method === 'import')
                        <label class="flex items-center gap-1.5 mt-1 text-xs text-gray-500 dark:text-gray-400">
                            <input type="checkbox" wire:model="keepInboxFiles"> Keep the originals in the inbox (copy and verify only)
                        </label>
                    @endif
                    @if($taskStatus)
                        <p class="text-xs mt-0.5 {{ $taskStatus['state'] === 'failed' ? 'text-red-500' : (in_array($taskStatus['state'], ['queued', 'running']) ? 'text-amber-500' : 'text-gray-400 dark:text-gray-600') }}">
                            @if(in_array($taskStatus['state'], ['queued', 'running']))<i class="fa-solid fa-spinner fa-spin mr-1"></i>{{ ucfirst($taskStatus['state']) }}…
                            @else {{ $taskStatus['state'] === 'failed' ? 'Failed: ' : 'Last run: ' }}{{ $taskStatus['message'] ?? '' }} @endif
                        </p>
                    @endif
                </div>
                <button wire:click="{{ $method }}" @disabled(!$enabled)
                        class="shrink-0 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-stone-800 hover:bg-gray-200 dark:hover:bg-stone-700 disabled:opacity-40 rounded-xl transition-colors">
                    Run
                </button>
            </div>
        @endforeach
    </x-card>
</div>
