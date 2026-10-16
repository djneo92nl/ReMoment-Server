@props(['devices'])
{{-- One "Play on device…" picker for a whole page. Anything on the page opens it with
     `$dispatch('play-on-device', { action: <DeviceAction::template>, label: 'what plays' })`; no per-item modal. --}}
<div x-data="{ action: '', label: '' }"
     @play-on-device.window="action = $event.detail.action; label = $event.detail.label || ''; $dispatch('open-modal', 'play-on-device')">
    <x-modal name="play-on-device" maxWidth="md">
        <div class="bg-white dark:bg-stone-900">
            <div class="flex items-start justify-between px-6 pt-6 pb-4">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Play on device</h3>
                    <p class="mt-0.5 truncate text-sm text-gray-500 dark:text-gray-400" x-text="label"></p>
                </div>
                <x-modal-close name="play-on-device" class="ml-4" />
            </div>
            <form method="POST" class="flex max-h-[70vh] flex-col gap-2 overflow-y-auto px-4 pb-5">
                @csrf
                @forelse($devices as $device)
                    <button type="submit" :formaction="action.replace('{id}', {{ $device->id }})"
                            class="flex items-center justify-between gap-3 rounded-2xl bg-gray-100 px-4 py-3 text-left text-sm font-medium text-gray-900 transition-colors hover:bg-gray-200 dark:bg-stone-800 dark:text-gray-100 dark:hover:bg-stone-700">
                        <span class="truncate">{{ $device->device_name }}</span>
                        <i class="fa-solid fa-play text-xs text-gray-400"></i>
                    </button>
                @empty
                    <p class="py-6 text-center text-sm text-gray-400 dark:text-gray-600">No compatible devices available.</p>
                @endforelse
            </form>
        </div>
    </x-modal>
</div>
