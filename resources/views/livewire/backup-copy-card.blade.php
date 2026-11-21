<div data-test="backup-copy">
    @auth
        <x-card class="p-6">
            <div class="text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600 mb-3">Backup copy</div>
            <p class="text-sm text-gray-500 max-w-xl">
                After each backup, also copy the archive to another place: a mounted network share or a USB drive. The newest {{ $keep }} are kept there too.
                A backup on the disk that dies is not a backup. Only a folder path, no cloud account; if the folder is missing or full the backup still succeeds and an alert appears.
            </p>

            <form wire:submit="save" class="mt-4 flex flex-wrap items-end gap-3">
                <div class="grow min-w-[16rem] max-w-md">
                    <label for="backup-copy-path" class="block text-xs text-gray-500 mb-1">Folder</label>
                    <x-text-input id="backup-copy-path" type="text" wire:model="path" placeholder="/mnt/nas/remoment-backups" class="w-full font-mono" autocomplete="off" data-test="backup-copy-path" />
                </div>
                <x-secondary-button size="md" wire:click="test" data-test="backup-copy-test">Test write</x-secondary-button>
                <x-primary-button size="md" type="submit" data-test="backup-copy-save">Save</x-primary-button>
            </form>
            <p class="mt-2 text-xs text-gray-500">Leave empty to switch the copy off. The folder is never created for you, so an unmounted share is noticed.</p>

            @if($result)
                <x-alert :type="$result['ok'] ? 'success' : 'error'" class="mt-4" data-test="backup-copy-result">{{ $result['message'] }}</x-alert>
            @endif

            @if($saved && $last)
                <div class="mt-4 text-sm" data-test="backup-copy-last">
                    @if($last['ok'])
                        <span class="text-emerald-600 dark:text-emerald-500">Last copy {{ \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans() }}</span>
                    @else
                        <span class="text-red-600 dark:text-red-500">Last copy failed {{ \Illuminate\Support\Carbon::parse($last['at'])->diffForHumans() }}:</span>
                        <span class="text-gray-500">{{ $last['message'] }}</span>
                    @endif
                </div>
            @elseif($saved)
                <div class="mt-4 text-sm text-gray-500" data-test="backup-copy-last">Waiting for the next backup.</div>
            @endif
        </x-card>
    @endauth
</div>
